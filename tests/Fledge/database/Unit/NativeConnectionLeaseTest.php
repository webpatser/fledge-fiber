<?php

use Fledge\Async\DeferredFuture;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\FiberDatabaseServiceProvider;
use Fledge\Fiber\Database\Native\FiberAwareTransactionsManager;
use Fledge\Fiber\Database\Native\GuardedPdo;
use Fledge\Fiber\Database\Native\Lease;
use Fledge\Fiber\Database\Native\LeaseGuard;
use Fledge\Fiber\Database\Native\LeaseOwnershipException;
use Fledge\Fiber\Database\Native\LeaseScope;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Lease scoping of the native connection on a real NativePdoPool, against
 * guarded PDO doubles (no server). `sleep <seconds>` statements suspend the
 * calling fiber, so Revolt interleaves fibers like a slow query would.
 */

/**
 * Statement double returning $rows; execute() goes through the lease guard.
 */
function nativeLeaseFakeStatement(array $rows, LeaseGuard $guard): PDOStatement
{
    return new class($rows, $guard) extends PDOStatement
    {
        private int $position = 0;

        public function __construct(private array $rows, private LeaseGuard $guard) {}

        public function setFetchMode(int $mode, mixed ...$args): true
        {
            return true;
        }

        public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
        {
            return true;
        }

        public function execute(?array $params = null): bool
        {
            return $this->guard->run(fn () => true);
        }

        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->rows;
        }

        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return $this->rows[$this->position++] ?? false;
        }

        public function rowCount(): int
        {
            return count($this->rows);
        }
    };
}

/**
 * Guarded PDO double. Every statement is logged; $onQuery may throw to
 * simulate a lost connection or an interrupted (waiter) call.
 */
function nativeLeaseFakePdo(int $id, array $rows = [], ?Closure $onQuery = null): PDO
{
    return new class($id, $rows, $onQuery) extends PDO implements GuardedPdo
    {
        /** @var list<string> */
        public array $log = [];

        public bool $open = false;

        private LeaseGuard $guard;

        public function __construct(public int $id, private array $rows, private ?Closure $onQuery)
        {
            $this->guard = new LeaseGuard;
        }

        public function leaseGuard(): LeaseGuard
        {
            return $this->guard;
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return $this->guard->run(function () use ($query) {
                $this->hit($query);

                return nativeLeaseFakeStatement($this->rows, $this->guard);
            });
        }

        public function exec(string $statement): int|false
        {
            return $this->guard->run(function () use ($statement) {
                $this->hit($statement);

                return 0;
            });
        }

        public function beginTransaction(): bool
        {
            return $this->guard->run(function () {
                $this->open = true;
                $this->log[] = 'begin';

                return true;
            });
        }

        public function commit(): bool
        {
            return $this->guard->run(function () {
                // Through hit(), so $onQuery can fail the COMMIT itself.
                $this->hit('commit');
                $this->open = false;

                return true;
            });
        }

        public function rollBack(): bool
        {
            return $this->guard->run(function () {
                // Through hit(), so $onQuery can fail the ROLLBACK itself.
                $this->hit('rollback');
                $this->open = false;

                return true;
            });
        }

        public function inTransaction(): bool
        {
            return $this->open;
        }

        public function lastInsertId(?string $name = null): string|false
        {
            return (string) $this->id;
        }

        private function hit(string $sql): void
        {
            if ($this->onQuery !== null) {
                ($this->onQuery)($sql, $this);
            }

            $this->log[] = $sql;

            if (str_starts_with($sql, 'sleep ')) {
                delay((float) substr($sql, 6));
            }
        }
    };
}

/**
 * A pool of fake PDOs plus a connection on it. $pdos collects every PDO the
 * pool opened, keyed by id.
 */
function nativeLeaseSetup(int $size, array $rows = [], ?Closure $onQuery = null): array
{
    $pdos = new ArrayObject;

    $pool = new NativePdoPool(function () use ($pdos, $rows, $onQuery) {
        $pdo = nativeLeaseFakePdo(count($pdos) + 1, $rows, $onQuery);
        $pdos[$pdo->id] = $pdo;

        return $pdo;
    }, $size, 60.0);

    $connection = new NativeMariaDbConnection($pool, 'native_lease', '', [
        'name' => 'native-lease',
        'driver' => 'fledge-mariadb-native',
    ]);

    return [$connection, $pool, $pdos];
}

it('holds a lease only while run() executes, in a fiber and in the main context', function () {
    $leasedDuringQuery = [];

    [$connection, $pool] = nativeLeaseSetup(4, [], function () use (&$leasedDuringQuery, &$pool) {
        $leasedDuringQuery[] = $pool->getLeasedCount();
    });

    $connection->unprepared('select 1');

    expect($pool->getLeasedCount())->toBe(0)
        ->and($pool->getIdleCount())->toBe(1);

    $leasedAfterFiberQuery = async(function () use ($connection, $pool) {
        $connection->select('select 2');

        return $pool->getLeasedCount();
    })->await();

    expect($leasedDuringQuery)->toBe([1, 1])
        ->and($leasedAfterFiberQuery)->toBe(0)
        ->and($pool)->toHaveCount(1);
});

it('pins the lease for a transaction until the final commit', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(1);
    $waitingInsideTransaction = null;

    $a = async(function () use ($connection, $pool, &$waitingInsideTransaction) {
        $connection->transaction(function ($c) use ($pool, &$waitingInsideTransaction) {
            $c->unprepared('a1');
            $c->unprepared('sleep 0.02');
            $waitingInsideTransaction = $pool->getWaitingCount();
            $c->unprepared('a2');
        });
    });

    $b = async(function () use ($connection) {
        delay(0.005);
        $connection->unprepared('b1');
    });

    await([$a, $b]);

    expect($pdos[1]->log)->toBe(['begin', 'a1', 'sleep 0.02', 'a2', 'commit', 'b1'])
        ->and($waitingInsideTransaction)->toBe(1)
        ->and($pool)->toHaveCount(1)
        ->and($pool->getLeasedCount())->toBe(0);

    // A bare beginTransaction() pins too, until commit().
    $connection->beginTransaction();
    expect($pool->getLeasedCount())->toBe(1);

    $connection->unprepared('inside');
    expect($pool->getLeasedCount())->toBe(1);

    $connection->commit();
    expect($pool->getLeasedCount())->toBe(0);
});

it('holds the lease while a cursor is consumed and releases it when the generator ends', function () {
    $rows = [(object) ['n' => 1], (object) ['n' => 2], (object) ['n' => 3]];
    [$connection, $pool] = nativeLeaseSetup(1, $rows);
    $events = [];

    $a = async(function () use ($connection, &$events) {
        foreach ($connection->cursor('select n') as $row) {
            $events[] = "A:{$row->n}";
            delay(0.005);
        }

        $events[] = 'A:done';
    });

    $b = async(function () use ($connection, &$events) {
        delay(0.001);
        $connection->unprepared('b');
        $events[] = 'B';
    });

    await([$a, $b]);

    expect($events)->toBe(['A:1', 'A:2', 'A:3', 'A:done', 'B'])
        ->and($pool->getLeasedCount())->toBe(0);
});

it('rejects a cursor resumed by another fiber and still releases its lease', function () {
    $rows = [(object) ['n' => 1], (object) ['n' => 2]];
    [$connection, $pool] = nativeLeaseSetup(2, $rows);

    $cursor = $connection->cursor('select n');

    expect($cursor->current()->n)->toBe(1)
        ->and($pool->getLeasedCount())->toBe(1);

    $error = async(function () use ($cursor) {
        try {
            $cursor->next();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    })->await();

    expect($error)->toBeInstanceOf(LeaseOwnershipException::class)
        ->and($error->getMessage())->toContain('consumed by the fiber that started it')
        ->and($pool->getLeasedCount())->toBe(0);
});

it('drops only the calling fiber\'s lease on a lost connection and retries on a fresh one', function () {
    $lost = false;

    [$connection, $pool, $pdos] = nativeLeaseSetup(3, [], function (string $sql) use (&$lost) {
        if ($sql === 'boom' && ! $lost) {
            $lost = true;

            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    });

    $b = async(function () use ($connection) {
        $connection->transaction(function ($c) {
            $c->unprepared('b1');
            $c->unprepared('sleep 0.02');
            $c->unprepared('b2');
        });

        return $connection->transactionLevel();
    });

    $a = async(function () use ($connection) {
        delay(0.005);

        return [$connection->unprepared('boom'), $connection->transactionLevel()];
    });

    [$levelB, [$resultA, $levelA]] = await([$b, $a]);

    expect($resultA)->toBeTrue()
        ->and($levelA)->toBe(0)
        ->and($levelB)->toBe(0)
        // B's transaction never noticed A's reconnect.
        ->and($pdos[1]->log)->toBe(['begin', 'b1', 'sleep 0.02', 'b2', 'commit'])
        // A's broken connection was discarded; the retry ran on a fresh one.
        ->and($pdos[2]->log)->toBe([])
        ->and($pdos[3]->log)->toBe(['boom'])
        ->and($pool->isLeased($pdos[2]))->toBeFalse()
        ->and($pool)->toHaveCount(2)
        ->and($pool->getIdleCount())->toBe(2);
});

it('throws LeaseOwnershipException when a PDO is used by a fiber that does not own its lease', function () {
    [$connection, $pool] = nativeLeaseSetup(2);
    $box = [];

    $holder = async(function () use ($connection, &$box) {
        $box['pdo'] = $connection->getPdo();
        delay(0.02);
        $connection->unprepared('holder done');
    });

    delay(0.005);

    expect(fn () => $box['pdo']->exec('main steals it'))
        ->toThrow(LeaseOwnershipException::class, 'leased to another fiber');

    $holder->await();

    // After release, any stale reference is rejected too.
    $stale = $connection->getPdo();
    $connection->unprepared('releases the main lease');

    expect(fn () => $stale->exec('use after release'))
        ->toThrow(LeaseOwnershipException::class, 'released back')
        ->and($pool->getLeasedCount())->toBe(0);
});

it('discards a connection whose call was interrupted by a non-PDO exception', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql) {
        if ($sql === 'interrupt') {
            throw new LogicException('waiter gave up');
        }
    });

    expect(fn () => $connection->unprepared('interrupt'))->toThrow(QueryException::class, 'waiter gave up')
        ->and($pdos[1]->leaseGuard()->isBroken())->toBeTrue()
        ->and($pool)->toHaveCount(0);

    $connection->unprepared('next');

    expect($pdos[2]->log)->toBe(['next'])
        ->and($pool)->toHaveCount(1);
});

it('keeps a broken transaction connection pinned until rollback, then drops it', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql) {
        if ($sql === 'interrupt') {
            throw new LogicException('waiter gave up');
        }
    });

    $connection->beginTransaction();

    expect(fn () => $connection->unprepared('interrupt'))->toThrow(QueryException::class, 'waiter gave up')
        // Never continue on a fresh connection outside the transaction.
        ->and(fn () => $connection->unprepared('after'))->toThrow(QueryException::class, 'Lost connection')
        ->and($connection->transactionLevel())->toBe(1)
        ->and($pool->getLeasedCount())->toBe(1);

    $connection->rollBack();

    expect($connection->transactionLevel())->toBe(0)
        ->and($pool)->toHaveCount(0)
        ->and($pdos)->toHaveCount(1);
});

it('resets only the caller\'s transaction level on setPdo(null) and fails other fibers\' open transactions', function () {
    [$connection, $pool] = nativeLeaseSetup(4);

    $fiber = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        $connection->beginTransaction();
        Fiber::suspend($connection->transactionLevel());

        $level = $connection->transactionLevel();

        try {
            $connection->unprepared('after setPdo');
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        $connection->rollBack(0);

        return [$level, $error, $connection->transactionLevel()];
    });

    expect($fiber->start())->toBe(2);

    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(1);

    $connection->setPdo(null);

    expect($connection->transactionLevel())->toBe(0);

    $fiber->resume();

    [$levelAfterSetPdo, $error, $levelAfterRollBack] = $fiber->getReturn();

    expect($levelAfterSetPdo)->toBe(2)
        ->and($error)->toBeInstanceOf(LostConnectionException::class)
        ->and($error->getMessage())->toContain('Lost connection')
        ->and($levelAfterRollBack)->toBe(0)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($pool->isClosed())->toBeTrue()
        ->and($pool->getLeasedCount())->toBe(0);
});

it('fails a fiber\'s open transaction instead of silently dropping it when another fiber disconnects', function (bool $reconnectRightAway, bool $queryAfterDisconnect) {
    [$connection, $pool, $pdos] = nativeLeaseSetup(4);
    $connection->setTransactionManager(new DatabaseTransactionsManager);

    $freshPdos = new ArrayObject;
    $freshPool = new NativePdoPool(function () use ($freshPdos) {
        $pdo = nativeLeaseFakePdo(100 + count($freshPdos));
        $freshPdos[] = $pdo;

        return $pdo;
    }, 4, 60.0);

    // What DatabaseManager::reconnect() does: disconnect, then install fresh PDOs.
    $connection->setReconnector(function ($c) use ($freshPool) {
        $c->disconnect();
        $c->setPdo($freshPool);
    });

    $events = [];

    $b = async(function () use ($connection, $queryAfterDisconnect, &$events) {
        try {
            $connection->transaction(function ($c) use ($queryAfterDisconnect, &$events) {
                $c->afterCommit(function () use (&$events) {
                    $events[] = 'B after commit';
                });
                $c->afterRollBack(function () use (&$events) {
                    $events[] = 'B after rollback';
                });

                $c->unprepared('b1');
                $c->unprepared('sleep 0.02');

                if ($queryAfterDisconnect) {
                    $c->unprepared('b2');
                }
            });
        } catch (Throwable $e) {
            return [$e, $connection->transactionLevel()];
        }

        return [null, $connection->transactionLevel()];
    });

    $a = async(function () use ($connection, $freshPool, $reconnectRightAway) {
        delay(0.005);

        $connection->disconnect();

        if ($reconnectRightAway) {
            $connection->setPdo($freshPool);
        }

        return $connection->transactionLevel();
    });

    [[$error, $levelB], $levelA] = await([$b, $a]);

    expect($error)->not->toBeNull()
        ->and($error->getMessage())->toContain('Lost connection')
        ->and($levelB)->toBe(0)
        ->and($levelA)->toBe(0)
        ->and($events)->toBe(['B after rollback'])
        // B's COMMIT was never sent, and its broken connection was dropped.
        ->and($pdos[1]->log)->toBe(['begin', 'b1', 'sleep 0.02'])
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool)->toHaveCount(0);

    // Later work runs on the fresh pool.
    $connection->unprepared('after');

    expect($freshPdos[0]->log)->toBe(['after'])
        ->and($freshPool->getLeasedCount())->toBe(0);
})->with([
    'reconnect right away' => [true],
    'reconnect on next query' => [false],
])->with([
    'query after disconnect' => [true],
    'commit after disconnect' => [false],
]);

it('wraps db.transactions without a native driver at boot, so a native connection added later keeps callbacks per fiber', function () {
    $container = new Container;
    $container->singleton('db.transactions', fn () => new DatabaseTransactionsManager);
    (new FiberDatabaseServiceProvider($container))->register();

    // Resolved at boot: no native connection exists yet.
    $manager = $container->make('db.transactions');

    // Added at runtime.
    [$connection] = nativeLeaseSetup(2);
    $connection->setTransactionManager($manager);

    expect($manager)->toBeInstanceOf(FiberAwareTransactionsManager::class);

    $events = [];

    $a = async(function () use ($connection, $container, &$events) {
        $connection->transaction(function ($c) use ($container, &$events) {
            // How queued jobs, broadcasts and events register: on the container binding.
            $container->make('db.transactions')->addCallback(function () use (&$events) {
                $events[] = 'A callback';
            });
            $c->unprepared('sleep 0.02');
        });

        $events[] = 'A committed';
    });

    $b = async(function () use ($connection, $container, &$events) {
        delay(0.005);

        $connection->transaction(function () use ($container, &$events) {
            $container->make('db.transactions')->addCallback(function () use (&$events) {
                $events[] = 'B callback';
            });
        });

        $events[] = 'B committed';

        // Outside its own transaction a fiber's callback runs right away.
        $container->make('db.transactions')->addCallback(function () use (&$events) {
            $events[] = 'B outside';
        });
    });

    await([$a, $b]);

    expect($events)->toBe(['B callback', 'B committed', 'B outside', 'A callback', 'A committed']);
});

it('leaves an app\'s own DatabaseTransactionsManager subclass bound as is', function () {
    $custom = new class extends DatabaseTransactionsManager
    {
        public function appSpecific(): string
        {
            return 'kept';
        }
    };

    $container = new Container;
    $container->singleton('db.transactions', fn () => $custom);
    (new FiberDatabaseServiceProvider($container))->register();

    $manager = $container->make('db.transactions');

    expect($manager)->toBe($custom)
        ->and($manager)->not->toBeInstanceOf(FiberAwareTransactionsManager::class)
        ->and($manager->appSpecific())->toBe('kept');

    // A native connection still wraps it for itself, per connection.
    [$connection] = nativeLeaseSetup(2);
    $connection->setTransactionManager($manager);

    $wrapped = (fn () => $this->transactionsManager)->call($connection);

    expect($wrapped)->toBeInstanceOf(FiberAwareTransactionsManager::class)
        ->and($wrapped->getMainManager())->toBe($custom)
        ->and($container->make('db.transactions'))->toBe($custom);
});

it('rolls back a fiber\'s transaction records when COMMIT fails with a server error, so a reused fiber starts clean', function (string $message) {
    $failCommit = true;

    [$connection, $pool] = nativeLeaseSetup(2, [], function (string $sql) use (&$failCommit, $message) {
        if ($sql === 'commit' && $failCommit) {
            $failCommit = false;

            throw new PDOException($message);
        }
    });
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $events = [];

    // Two tasks back to back on one fiber, like a Revolt callback fiber reused for the next callback.
    $fiber = new Fiber(function () use ($connection, &$events) {
        try {
            $connection->transaction(function ($c) use (&$events) {
                $c->afterCommit(function () use (&$events) {
                    $events[] = 'first after commit';
                });
                $c->afterRollBack(function () use (&$events) {
                    $events[] = 'first after rollback';
                });
                $c->unprepared('first');
            });
        } catch (PDOException) {
            $events[] = 'first failed';
        }

        $level = $connection->transactionLevel();

        // The next task, outside any transaction: the callback runs right away.
        $connection->afterCommit(function () use (&$events) {
            $events[] = 'second outside';
        });

        $connection->transaction(function ($c) use (&$events) {
            $c->afterCommit(function () use (&$events) {
                $events[] = 'second after commit';
            });
            $c->unprepared('second');
        });

        return $level;
    });

    $fiber->start();

    expect($fiber->isTerminated())->toBeTrue()
        ->and($fiber->getReturn())->toBe(0)
        ->and($events)->toBe(['first after rollback', 'first failed', 'second outside', 'second after commit'])
        ->and($connection->transactionLevel())->toBe(0)
        ->and($pool->getLeasedCount())->toBe(0);
})->with([
    'server gone away (2006)' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
    'deadlock on the last attempt' => ['SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'],
]);

it('keeps the shared pool and other fibers\' transactions when a rollback in one fiber loses the connection', function (int $level, int $toLevel) {
    $goneAway = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');

    [$connection, $pool, $pdos] = nativeLeaseSetup(4, [], function (string $sql, PDO $pdo) use ($goneAway) {
        if (stripos($sql, 'rollback') === 0) {
            // A dead connection no longer reports a transaction, so the pool
            // would take it back as reusable unless the lease is discarded.
            $pdo->open = false;

            throw $goneAway;
        }
    });
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $events = [];

    $a = async(function () use ($connection, $level, $toLevel, &$events) {
        for ($i = 0; $i < $level; $i++) {
            $connection->beginTransaction();
        }

        $connection->afterRollBack(function () use (&$events) {
            $events[] = 'A after rollback';
        });

        $connection->unprepared('a1');
        delay(0.005);

        try {
            $connection->rollBack($toLevel);
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        return [$error, $connection->transactionLevel()];
    });

    $b = async(function () use ($connection, &$events) {
        try {
            $connection->transaction(function ($c) use (&$events) {
                $c->afterCommit(function () use (&$events) {
                    $events[] = 'B after commit';
                });

                $c->unprepared('b1');
                $c->unprepared('sleep 0.02');
                $c->unprepared('b2');
            });
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    });

    [[$errorA, $levelA], $errorB] = await([$a, $b]);

    expect($errorB)->toBeNull()
        ->and($errorA)->toBe($goneAway)
        ->and($levelA)->toBe(0)
        ->and($pdos[2]->log)->toBe(['begin', 'b1', 'sleep 0.02', 'b2', 'commit'])
        ->and($events)->toBe(['A after rollback', 'B after commit'])
        ->and($connection->getRawPdo())->toBe($pool)
        ->and($pool->isClosed())->toBeFalse()
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool)->toHaveCount(1);

    // The lost connection never comes back out of the pool.
    $connection->unprepared('after');

    expect($pdos)->toHaveCount(2)
        ->and($pdos[1]->log)->not->toContain('after')
        ->and($pdos[2]->log)->toContain('after');
})->with([
    'full rollback' => [1, 0],
    'full rollback of a nested transaction' => [2, 0],
    'savepoint rollback' => [2, 1],
]);

it('keeps the shared pool and other fibers\' transactions when a commit in one fiber loses the connection', function () {
    $goneAway = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    $failCommit = true;

    [$connection, $pool, $pdos] = nativeLeaseSetup(4, [], function (string $sql, PDO $pdo) use ($goneAway, &$failCommit) {
        if ($sql === 'commit' && $failCommit) {
            $failCommit = false;
            // A dead connection no longer reports a transaction, so the pool
            // would take it back as reusable unless the lease is discarded.
            $pdo->open = false;

            throw $goneAway;
        }
    });
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $events = [];

    $a = async(function () use ($connection, &$events) {
        try {
            $connection->transaction(function ($c) use (&$events) {
                $c->afterRollBack(function () use (&$events) {
                    $events[] = 'A after rollback';
                });
                $c->unprepared('a1');
                delay(0.005);
            });
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        return [$error, $connection->transactionLevel()];
    });

    $b = async(function () use ($connection, &$events) {
        try {
            $connection->transaction(function ($c) use (&$events) {
                $c->afterCommit(function () use (&$events) {
                    $events[] = 'B after commit';
                });

                $c->unprepared('b1');
                $c->unprepared('sleep 0.02');
                $c->unprepared('b2');
            });
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    });

    [[$errorA, $levelA], $errorB] = await([$a, $b]);

    expect($errorB)->toBeNull()
        ->and($errorA)->toBe($goneAway)
        ->and($levelA)->toBe(0)
        ->and($pdos[2]->log)->toBe(['begin', 'b1', 'sleep 0.02', 'b2', 'commit'])
        ->and($events)->toBe(['A after rollback', 'B after commit'])
        ->and($connection->getRawPdo())->toBe($pool)
        ->and($pool->isClosed())->toBeFalse()
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool)->toHaveCount(1);

    // The lost connection never comes back out of the pool.
    $connection->unprepared('after');

    expect($pdos)->toHaveCount(2)
        ->and($pdos[1]->log)->not->toContain('after')
        ->and($pdos[2]->log)->toContain('after');
});

it('keeps the shared pool for fibers when a rollback in the main context loses the connection', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(4, [], function (string $sql) {
        if ($sql === 'rollback') {
            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    });

    $fiber = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        Fiber::suspend();
        $connection->unprepared('fiber');
        $connection->commit();
    });

    $fiber->start();

    $connection->beginTransaction();

    expect(fn () => $connection->rollBack())->toThrow(PDOException::class, 'gone away')
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getRawPdo())->toBe($pool);

    $fiber->resume();

    expect($fiber->isTerminated())->toBeTrue()
        ->and($pdos[1]->log)->toBe(['begin', 'fiber', 'commit'])
        ->and($pool->isLeased($pdos[2]))->toBeFalse()
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool)->toHaveCount(1);
});

it('leaves a replacement pool alone when a broken savepoint rollback follows a reconnect in another context', function () {
    [$connection, $pool] = nativeLeaseSetup(4);

    $freshPdo = nativeLeaseFakePdo(100);
    $freshPool = new NativePdoPool(fn () => $freshPdo, 4, 60.0);
    $factory = fn () => $freshPool;

    $fiber = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        $connection->beginTransaction();
        Fiber::suspend();

        try {
            $connection->rollBack(1);
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        return [$error, $connection->transactionLevel()];
    });

    $fiber->start();

    // What DatabaseManager::reconnect() leaves behind: a lazy factory in the raw slot.
    $connection->setPdo($factory);

    $fiber->resume();

    [$error, $level] = $fiber->getReturn();

    expect($error)->toBeInstanceOf(LostConnectionException::class)
        ->and($error->getMessage())->toContain('savepoint trans1')
        ->and($level)->toBe(0)
        ->and($pool->isClosed())->toBeTrue()
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($connection->getRawPdo())->toBe($factory);

    $connection->unprepared('fresh');

    expect($freshPdo->log)->toBe(['fresh'])
        ->and($connection->getRawPdo())->toBe($freshPool);
});

it('rethrows a rollback failure that is not a lost connection and keeps the transaction open', function () {
    $fail = true;

    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql) use (&$fail) {
        if ($sql === 'rollback' && $fail) {
            $fail = false;

            throw new PDOException('SQLSTATE[HY000]: General error: 1105 Unknown error');
        }
    });

    $connection->beginTransaction();

    expect(fn () => $connection->rollBack())->toThrow(PDOException::class, '1105')
        ->and($connection->transactionLevel())->toBe(1)
        ->and($pool->isLeased($pdos[1]))->toBeTrue()
        ->and($connection->getRawPdo())->toBe($pool);

    $connection->rollBack();

    expect($connection->transactionLevel())->toBe(0)
        ->and($pdos[1]->log)->toBe(['begin', 'rollback'])
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool->getIdleCount())->toBe(1);
});

it('keeps the stock behaviour for a raw PDO when a rollback loses the connection', function () {
    [$connection] = nativeLeaseSetup(2);

    $connection->setPdo(nativeLeaseFakePdo(50, [], function (string $sql) {
        if ($sql === 'rollback') {
            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    }));

    $connection->beginTransaction();

    expect(fn () => $connection->rollBack())->toThrow(PDOException::class, 'gone away')
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getRawPdo())->toBeNull();
});

/**
 * Two fibers on one stock (non-native) connection: B's transaction nests in
 * A's (shared level), plus callbacks registered on the manager outside any
 * transaction of B's own and a rolled back savepoint.
 *
 * @return list<string>
 */
function nativeLeaseStockCallbackScenario(DatabaseTransactionsManager $manager): array
{
    $connection = new MariaDbConnection(nativeLeaseFakePdo(1), 'stock', '', ['name' => 'stock']);
    $connection->setTransactionManager($manager);
    $events = [];

    $a = async(function () use ($connection, $manager, &$events) {
        $connection->transaction(function ($c) use ($manager, &$events) {
            $manager->addCallback(function () use (&$events) {
                $events[] = 'A after commit';
            });
            $c->unprepared('sleep 0.02');
            $events[] = 'A body done';
        });

        $events[] = 'A returned';
    });

    $b = async(function () use ($connection, $manager, &$events) {
        delay(0.005);

        $connection->transaction(function ($c) use (&$events) {
            $c->afterCommit(function () use (&$events) {
                $events[] = 'B after commit';
            });
        });

        try {
            $connection->transaction(function ($c) use (&$events) {
                $c->afterRollBack(function () use (&$events) {
                    $events[] = 'B after rollback';
                });

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
            $events[] = 'B rolled back';
        }

        $manager->addCallback(function () use (&$events) {
            $events[] = 'B outside';
        });

        $events[] = 'B returned';
    });

    await([$a, $b]);

    $manager->addCallback(function () use (&$events) {
        $events[] = 'main outside';
    });

    return $events;
}

it('keeps after-commit behaviour identical for apps without a native driver', function () {
    $container = new Container;
    $container->singleton('db.transactions', fn () => new DatabaseTransactionsManager);
    (new FiberDatabaseServiceProvider($container))->register();

    $wrapped = $container->make('db.transactions');

    $stock = nativeLeaseStockCallbackScenario(new DatabaseTransactionsManager);

    expect($wrapped)->toBeInstanceOf(FiberAwareTransactionsManager::class)
        ->and(nativeLeaseStockCallbackScenario($wrapped))->toBe($stock)
        ->and($stock)->toBe([
            'B after rollback',
            'B rolled back',
            'B returned',
            'A body done',
            'B after commit',
            'A after commit',
            'B outside',
            'A returned',
            'main outside',
        ]);
});

it('keeps lastInsertId per fiber across interleaved inserts', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2);

    $a = async(function () use ($connection) {
        $connection->insert('insert a');
        $connection->unprepared('sleep 0.02');

        return $connection->getLastInsertId();
    });

    $b = async(function () use ($connection) {
        delay(0.005);
        $connection->insert('insert b');

        return $connection->getLastInsertId();
    });

    [$idA, $idB] = await([$a, $b]);

    // The fibers' inserts never leak into the main context.
    $mainBeforeInsert = $connection->getLastInsertId();

    $connection->insert('insert main');

    expect($pdos[1]->log)->toBe(['insert a', 'sleep 0.02', 'insert main'])
        ->and($pdos[2]->log)->toBe(['insert b'])
        ->and($idA)->toBe('1')
        ->and($idB)->toBe('2')
        ->and($mainBeforeInsert)->toBeNull()
        ->and($connection->getLastInsertId())->toBe('1');
});

it('keeps afterCommit callbacks with the fiber whose transaction registered them', function () {
    [$connection] = nativeLeaseSetup(2);
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $events = [];

    $a = async(function () use ($connection, &$events) {
        $connection->transaction(function ($c) use (&$events) {
            $c->afterCommit(function () use (&$events) {
                $events[] = 'A callback';
            });
            $c->unprepared('sleep 0.02');
        });

        $events[] = 'A committed';
    });

    $b = async(function () use ($connection, &$events) {
        delay(0.005);

        $connection->transaction(function ($c) use (&$events) {
            $c->afterCommit(function () use (&$events) {
                $events[] = 'B callback';
            });
        });

        $events[] = 'B committed';
    });

    await([$a, $b]);

    expect($events)->toBe(['B callback', 'B committed', 'A callback', 'A committed']);
});

it('drops only the read lease when a read-only fiber loses its connection before the write PDO was ever resolved', function () {
    $lost = false;
    $readPdos = new ArrayObject;

    $readPool = new NativePdoPool(function () use ($readPdos, &$lost) {
        $pdo = nativeLeaseFakePdo(count($readPdos) + 1, [], function (string $sql) use (&$lost) {
            if ($sql === 'boom' && ! $lost) {
                $lost = true;
                // Fails mid-call, while another fiber waits for a read connection.
                delay(0.01);

                throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        });
        $readPdos[$pdo->id] = $pdo;

        return $pdo;
    }, 2, 60.0);

    $writeResolved = false;

    // The lazy write factory a read/write split leaves in the raw slot.
    $connection = new NativeMariaDbConnection(function () use (&$writeResolved) {
        $writeResolved = true;

        return new NativePdoPool(fn () => nativeLeaseFakePdo(100), 2, 60.0);
    }, 'native_lease', '', [
        'name' => 'native-lease',
        'driver' => 'fledge-mariadb-native',
    ]);
    $connection->setReadPdo($readPool);

    $reconnects = 0;

    // What DatabaseManager::reconnect() does: disconnect, then install fresh PDOs.
    $connection->setReconnector(function ($c) use (&$reconnects) {
        $reconnects++;
        $c->disconnect();
        $c->setPdo(fn () => new NativePdoPool(fn () => nativeLeaseFakePdo(200), 2, 60.0));
        $c->setReadPdo(new NativePdoPool(fn () => nativeLeaseFakePdo(300), 2, 60.0));
    });

    $select = function (string $sql, float $after) use ($connection) {
        return async(function () use ($connection, $sql, $after) {
            delay($after);

            try {
                return $connection->select($sql);
            } catch (Throwable $e) {
                return $e;
            }
        });
    };

    $b = $select('sleep 0.02', 0);
    $a = $select('boom', 0.002);
    $c = $select('c', 0.005);

    [$resultB, $resultA, $resultC] = await([$b, $a, $c]);

    expect($resultA)->toBe([])
        ->and($resultB)->toBe([])
        ->and($resultC)->toBe([])
        ->and($reconnects)->toBe(0)
        ->and($writeResolved)->toBeFalse()
        ->and($connection->getRawReadPdo())->toBe($readPool)
        ->and($readPool->isClosed())->toBeFalse()
        // A's broken connection was discarded, never handed to C.
        ->and($readPdos[2]->log)->toBe([])
        ->and($readPool->isLeased($readPdos[2]))->toBeFalse()
        ->and($readPdos[1]->log)->toBe(['sleep 0.02'])
        ->and($readPool->getLeasedCount())->toBe(0)
        ->and($readPool)->toHaveCount(2);
});

it('starts each Revolt callback with a clean transaction level, lease and insert id when the callback fiber is reused', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2);
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $events = [];
    $fibers = [];

    // Neither callback suspends, so Revolt runs both on the same callback fiber.
    $a = async(function () use ($connection, &$events, &$fibers) {
        $fibers[] = Fiber::getCurrent();

        $connection->insert('insert a');
        $connection->beginTransaction();
        $connection->afterCommit(function () use (&$events) {
            $events[] = 'A after commit';
        });
        $connection->unprepared('a1');

        throw new RuntimeException('A gave up');
    });

    $b = async(function () use ($connection, &$events, &$fibers) {
        $fibers[] = Fiber::getCurrent();

        $seen = [$connection->transactionLevel(), $connection->getLastInsertId()];

        $connection->transaction(function ($c) use (&$events) {
            $c->afterCommit(function () use (&$events) {
                $events[] = 'B after commit';
            });
            $c->unprepared('b1');
        });

        return [...$seen, $connection->transactionLevel()];
    });

    $seenByB = $b->await();

    expect(fn () => $a->await())->toThrow(RuntimeException::class, 'A gave up')
        ->and($fibers)->toHaveCount(2)
        ->and($fibers[0])->toBe($fibers[1])
        ->and($seenByB)->toBe([0, null, 0])
        ->and($events)->toBe(['B after commit'])
        // A's abandoned transaction never reached B: its connection was dropped, not reused.
        ->and($pdos[1]->log)->toBe(['insert a', 'begin', 'a1'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pdos[2]->log)->toBe(['begin', 'b1', 'commit'])
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool)->toHaveCount(1)
        ->and($connection->transactionLevel())->toBe(0);
});

it('returns a PDO taken outside any scope to the pool when the callback ends, without growing the pool', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(4);
    $taken = [];

    // The DB::getPdo() / isMaria() / escape() path: no run() scope around it.
    for ($i = 0; $i < 3; $i++) {
        async(function () use ($connection, $pool, &$taken) {
            $pdo = $connection->getPdo();
            $taken[] = [$pdo->id, $pool->getLeasedCount()];
        })->await();

        expect($pool->getLeasedCount())->toBe(0)
            ->and($pool->getIdleCount())->toBe(1);
    }

    $connection->unprepared('reused');

    expect($taken)->toBe([[1, 1], [1, 1], [1, 1]])
        ->and($pdos)->toHaveCount(1)
        ->and($pdos[1]->log)->toBe(['reused'])
        ->and($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1);
});

it('discards, never returns, the leases of a scope destroyed mid-transaction or mid-query', function (int $depth, int $transactions, bool $returned) {
    $pdos = new ArrayObject;
    $pool = new NativePdoPool(function () use ($pdos) {
        $pdo = nativeLeaseFakePdo(count($pdos) + 1);
        $pdos[$pdo->id] = $pdo;

        return $pdo;
    }, 2, 60.0);

    $pdo = $pool->acquire();
    // Report no open transaction, so only the scope state decides: the pool
    // would take a reported transaction back as unusable on its own.
    $pdo->open = false;

    $scope = new LeaseScope;
    $scope->depth = $depth;
    $scope->transactions = $transactions;
    $scope->leases['write'] = new Lease($pool, $pdo);

    unset($scope);

    expect($pool->isLeased($pdo))->toBeFalse()
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool->getIdleCount())->toBe($returned ? 1 : 0)
        ->and($pool)->toHaveCount($returned ? 1 : 0);

    // A returned connection is handed out again; a discarded one never is.
    expect($pool->acquire())->toBe($returned ? $pdo : $pdos[2]);
})->with([
    'idle scope' => [0, 0, true],
    'open transaction' => [0, 1, false],
    'unfinished query or cursor' => [1, 0, false],
    'query inside a transaction' => [1, 2, false],
]);

/**
 * $onQuery for nativeLeaseSetup(): a statement containing "dirty" records a
 * possible session change on the lease, as NativePdo does for SQL it cannot
 * prove clean.
 */
function nativeLeaseMarkDirty(): Closure
{
    return function (string $sql, PDO $pdo) {
        if (str_contains($sql, 'dirty')) {
            $pdo->leaseGuard()->markSessionChanged();
        }
    };
}

it('discards a lease whose session changed when the fiber ends and opens a new connection for the next lessee', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());

    $during = async(function () use ($connection, $pool) {
        $connection->unprepared('dirty set');
        $connection->unprepared('own next');

        return [$pool->isLeased($connection->getPdo()), count($pool)];
    })->await();

    expect($during)->toBe([true, 1])
        ->and($pool)->toHaveCount(0)
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool->getIdleCount())->toBe(0);

    async(fn () => $connection->unprepared('next'))->await();
    async(fn () => $connection->unprepared('clean again'))->await();

    expect($pdos)->toHaveCount(2)
        // The fiber's own next statement ran on its pinned connection.
        ->and($pdos[1]->log)->toBe(['dirty set', 'own next'])
        // A clean lessee keeps its connection: the second one serves both.
        ->and($pdos[2]->log)->toBe(['next', 'clean again'])
        ->and($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool->getLeasedCount())->toBe(0);
});

it('pins a dirty lease to its fiber for every following statement, a transaction included, and never lends it to another fiber', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(4, [], nativeLeaseMarkDirty());

    $a = async(function () use ($connection) {
        $connection->unprepared('a dirty');
        delay(0.02);
        $connection->select('a select');
        $connection->transaction(fn ($c) => $c->unprepared('a in transaction'));

        return $connection->getPdo()->id;
    });

    $b = async(function () use ($connection) {
        delay(0.01);
        $connection->unprepared('b clean');

        return $connection->getPdo()->id;
    });

    [$pdoOfA, $pdoOfB] = await([$a, $b]);

    expect($pdoOfA)->toBe(1)
        ->and($pdoOfB)->toBe(2)
        ->and($pdos[1]->log)->toBe(['a dirty', 'a select', 'begin', 'a in transaction', 'commit'])
        ->and($pdos[2]->log)->toBe(['b clean'])
        // A's connection was discarded when A ended; B's clean one went back.
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool->getLeasedCount())->toBe(0);
});

it('opens one extra connection, not one per statement, for a fiber running many dirty statements', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());

    async(function () use ($connection) {
        for ($i = 0; $i < 20; $i++) {
            $connection->select("select dirty {$i}");
        }
    })->await();

    async(fn () => $connection->select('select clean'))->await();

    expect($pdos)->toHaveCount(2)
        ->and($pdos[1]->log)->toHaveCount(20)
        ->and($pdos[2]->log)->toBe(['select clean'])
        ->and($pool)->toHaveCount(1);
});

it('lets a fiber that pinned the only connection of a pool of one keep querying, and hands a waiter a fresh one when it ends', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(1, [], nativeLeaseMarkDirty());
    $waiting = null;

    $a = async(function () use ($connection, $pool, &$waiting) {
        $connection->unprepared('a dirty');
        delay(0.02);
        $waiting = $pool->getWaitingCount();

        foreach (['a one', 'a two', 'a three'] as $sql) {
            $connection->unprepared($sql);
        }

        return $connection->getPdo()->id;
    });

    $b = async(function () use ($connection) {
        delay(0.01);
        $connection->unprepared('b');

        return $connection->getPdo()->id;
    });

    [$pdoOfA, $pdoOfB] = await([$a, $b]);

    expect($waiting)->toBe(1)
        ->and($pdoOfA)->toBe(1)
        ->and($pdoOfB)->toBe(2)
        ->and($pdos[1]->log)->toBe(['a dirty', 'a one', 'a two', 'a three'])
        ->and($pdos[2]->log)->toBe(['b'])
        ->and($pool)->toHaveCount(1)
        ->and($pool->getWaitingCount())->toBe(0)
        ->and($pool->getLeasedCount())->toBe(0);
});

it('keeps the main context pin across statements until disconnect() releases it', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());

    $connection->unprepared('dirty set');
    $connection->select('select after');

    $pinned = [$pool->isLeased($pdos[1]), count($pool)];

    $connection->disconnect();

    expect($pinned)->toBe([true, 1])
        ->and($pdos)->toHaveCount(1)
        ->and($pdos[1]->log)->toBe(['dirty set', 'select after'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool->getLeasedCount())->toBe(0);
});

/**
 * A pool of fake PDOs numbered from $firstId; $pdos collects them by id.
 *
 * @return array{0: NativePdoPool, 1: ArrayObject}
 */
function nativeLeasePool(int $firstId, int $size = 2, ?Closure $onQuery = null): array
{
    $pdos = new ArrayObject;

    $pool = new NativePdoPool(function () use ($pdos, $firstId, $onQuery) {
        $pdo = nativeLeaseFakePdo($firstId + count($pdos), [], $onQuery);
        $pdos[$pdo->id] = $pdo;

        return $pdo;
    }, $size, 60.0);

    return [$pool, $pdos];
}

it('fails a dirty pin once when another context replaces the pool, then runs on a fresh session', function (bool $inFiber, string $how) {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());
    [$replacement, $fresh] = nativeLeasePool(100, 1);
    $reconnects = 0;

    // What DatabaseManager::reconnect() installs after a disconnect().
    $connection->setReconnector(function ($c) use ($replacement, &$reconnects) {
        $reconnects++;
        $c->setPdo($replacement);
    });

    $replace = $how === 'setPdo'
        ? fn () => $connection->setPdo($replacement)
        : fn () => $connection->disconnect();

    $holder = function (Closure $meanwhile) use ($connection) {
        $connection->unprepared('holder dirty');
        $meanwhile();

        try {
            $connection->unprepared('holder after');
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        $connection->unprepared('holder next');

        return $error;
    };

    if ($inFiber) {
        $fiber = new Fiber(fn () => $holder(fn () => Fiber::suspend()));
        $fiber->start();
        $replace();
        $fiber->resume();
        $error = $fiber->getReturn();
    } else {
        $error = $holder(fn () => async($replace)->await(new TimeoutCancellation(5)));
    }

    expect($error)->toBeInstanceOf(LostConnectionException::class)
        ->and($error->getMessage())->toContain('Lost connection')
        // The statement after the replacement never ran, on either session.
        ->and($pdos[1]->log)->toBe(['holder dirty'])
        ->and($fresh)->toHaveCount(1)
        ->and($fresh[100]->log)->toBe(['holder next'])
        ->and($reconnects)->toBe($how === 'setPdo' ? 0 : 1)
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(0)
        ->and($replacement->getLeasedCount())->toBe(0)
        ->and($replacement->getIdleCount())->toBe(1);
})->with([
    'held by a fiber' => true,
    'held by the main context' => false,
])->with(['setPdo', 'disconnect']);

it('fails a pin once when another fiber disconnects during the pinned fiber\'s in-flight statement, and keeps a clean lease silent', function (?string $before, string $inFlight, bool $fails) {
    $started = new DeferredFuture;
    $markDirty = nativeLeaseMarkDirty();

    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql, PDO $pdo) use ($markDirty, $started) {
        $markDirty($sql, $pdo);

        if (str_starts_with($sql, 'sleep ') && ! $started->isComplete()) {
            $started->complete();
        }
    });
    [$replacement, $fresh] = nativeLeasePool(100, 1);
    $events = [];

    // What DatabaseManager::reconnect() installs after a disconnect().
    $connection->setReconnector(fn ($c) => $c->setPdo($replacement));

    $a = async(function () use ($connection, $before, $inFlight, &$events) {
        if ($before !== null) {
            $connection->unprepared($before);
        }

        $connection->unprepared($inFlight);
        $events[] = 'in-flight statement returned';

        try {
            $connection->unprepared('a after');
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        $connection->unprepared('a next');

        return $error;
    });

    // Triggered by the in-flight statement reaching the connection, not by timing.
    $b = async(function () use ($connection, $started, &$events) {
        $started->getFuture()->await(new TimeoutCancellation(2));
        $connection->disconnect();
        $events[] = 'disconnected';
    });

    [$error] = await([$a, $b], new TimeoutCancellation(5));

    $ranOnOld = $before !== null ? [$before, $inFlight] : [$inFlight];

    expect($events)->toBe(['disconnected', 'in-flight statement returned'])
        ->and($pdos)->toHaveCount(1)
        // The closed pool's connection was dropped exactly once: nothing leased, nothing open.
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(0)
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($replacement->getLeasedCount())->toBe(0)
        ->and($replacement->getIdleCount())->toBe(1);

    if ($fails) {
        expect($error)->toBeInstanceOf(LostConnectionException::class)
            // The statement after the disconnect never ran, on either session.
            ->and($pdos[1]->log)->toBe($ranOnOld)
            ->and($fresh[100]->log)->toBe(['a next']);
    } else {
        expect($error)->toBeNull()
            ->and($pdos[1]->log)->toBe($ranOnOld)
            ->and($fresh[100]->log)->toBe(['a after', 'a next']);
    }
})->with([
    'pinned before the statement' => ['a dirty', 'sleep 0.05', true],
    'dirtied by the in-flight statement' => [null, 'sleep 0.05 dirty', true],
    'clean lease' => ['a clean', 'sleep 0.05', false],
]);

it('moves a clean lease to the replacement pool without an error when another context replaces the pool', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());
    [$replacement, $fresh] = nativeLeasePool(100, 1);

    $fiber = new Fiber(function () use ($connection) {
        // Taken outside any scope, so the clean lease is kept past this call.
        $held = $connection->getPdo()->id;
        Fiber::suspend();
        $connection->unprepared('after replace');

        return $held;
    });

    $fiber->start();
    $connection->setPdo($replacement);
    $fiber->resume();

    expect($fiber->getReturn())->toBe(1)
        ->and($pdos[1]->log)->toBe([])
        ->and($fresh[100]->log)->toBe(['after replace'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($replacement->getLeasedCount())->toBe(0)
        ->and($replacement->getIdleCount())->toBe(1);
});

it('pins dirty write and read leases each on their own, and a replaced write pool fails the write pin only', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());
    [$readPool, $readPdos] = nativeLeasePool(10, 2, nativeLeaseMarkDirty());
    [$replacement, $fresh] = nativeLeasePool(100, 1);
    $connection->setReadPdo($readPool);

    $readPinnedAfter = null;

    $a = async(function () use ($connection, $readPool, &$readPinnedAfter) {
        $connection->unprepared('write dirty');
        $connection->select('read dirty');
        $connection->unprepared('write own');
        $connection->select('read own');

        $pinned = [$connection->getRawPdo()->getLeasedCount(), $readPool->getLeasedCount()];

        // The main context replaces the write pool meanwhile.
        delay(0.02);

        try {
            $connection->select('read after replace');
            $error = null;
        } catch (LostConnectionException $e) {
            $error = $e;
        }

        $connection->select('read again');
        $connection->unprepared('write next');
        $readPinnedAfter = $readPool->getLeasedCount();

        return [$pinned, $error];
    });

    delay(0.01);
    $connection->setPdo($replacement);

    // The pins end with the fiber's callback.
    [$pinned, $error] = $a->await(new TimeoutCancellation(5));

    expect($pinned)->toBe([1, 1])
        ->and($error)->toBeInstanceOf(LostConnectionException::class)
        ->and($pdos[1]->log)->toBe(['write dirty', 'write own'])
        // The read pin survives: its pool was not replaced.
        ->and($readPdos)->toHaveCount(1)
        ->and($readPdos[10]->log)->toBe(['read dirty', 'read own', 'read again'])
        ->and($fresh[100]->log)->toBe(['write next'])
        ->and($readPinnedAfter)->toBe(1)
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($readPool->getLeasedCount())->toBe(0)
        ->and($readPool)->toHaveCount(0)
        ->and($replacement->getLeasedCount())->toBe(0);
});

it('discards a dirty pin when its fiber ends by an exception, so the next lessee gets a fresh connection', function (string $failure) {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql, PDO $pdo) {
        nativeLeaseMarkDirty()($sql, $pdo);

        if ($sql === 'bad') {
            throw new PDOException('SQLSTATE[42000]: Syntax error or access violation: 1064 bad');
        }
    });

    $a = async(function () use ($connection, $failure) {
        $connection->unprepared('a dirty');
        $connection->unprepared('a own');

        $failure === 'statement'
            ? $connection->unprepared('bad')
            : throw new RuntimeException('job failed');
    });

    expect(fn () => $a->await(new TimeoutCancellation(5)))->toThrow($failure === 'statement' ? QueryException::class : RuntimeException::class);

    async(fn () => $connection->unprepared('next'))->await(new TimeoutCancellation(5));

    expect($pdos)->toHaveCount(2)
        ->and($pdos[1]->log)->toBe(['a dirty', 'a own'])
        ->and($pdos[2]->log)->toBe(['next'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool->getLeasedCount())->toBe(0);
})->with(['statement', 'job']);

it('carries the main context session from job to job until DB::disconnect() clears it', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());
    [$replacement, $fresh] = nativeLeasePool(100, 2, nativeLeaseMarkDirty());
    $connection->setReconnector(fn ($c) => $c->setPdo($replacement));

    // A queue worker's loop in the main context: each job runs its statements.
    $jobs = [['job 1 dirty'], ['job 2'], ['job 3']];

    foreach ($jobs as $statements) {
        foreach ($statements as $sql) {
            $connection->unprepared($sql);
        }
    }

    $carried = [$pool->getLeasedCount(), count($pdos)];

    $connection->disconnect();

    $connection->unprepared('job 4');
    $connection->unprepared('job 5');

    expect($carried)->toBe([1, 1])
        ->and($pdos[1]->log)->toBe(['job 1 dirty', 'job 2', 'job 3'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(0)
        ->and($fresh)->toHaveCount(1)
        ->and($fresh[100]->log)->toBe(['job 4', 'job 5'])
        ->and($replacement->getLeasedCount())->toBe(0)
        ->and($replacement->getIdleCount())->toBe(1);
});

it('keeps the read pin when a lost write connection is reconnected', function () {
    $lost = false;

    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql, PDO $pdo) use (&$lost) {
        nativeLeaseMarkDirty()($sql, $pdo);

        if ($sql === 'boom' && ! $lost) {
            $lost = true;

            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    });
    [$readPool, $readPdos] = nativeLeasePool(10, 2, nativeLeaseMarkDirty());
    $connection->setReadPdo($readPool);

    $result = async(function () use ($connection) {
        $connection->select('read dirty');
        $connection->unprepared('write dirty');
        $result = $connection->unprepared('boom');
        $connection->select('read after');

        return $result;
    })->await(new TimeoutCancellation(5));

    expect($result)->toBeTrue()
        ->and($pdos[1]->log)->toBe(['write dirty'])
        ->and($pdos[2]->log)->toBe(['boom'])
        // reconnect() dropped only the lost write lease: the read session carried on.
        ->and($readPdos)->toHaveCount(1)
        ->and($readPdos[10]->log)->toBe(['read dirty', 'read after'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(1)
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($readPool)->toHaveCount(0)
        ->and($readPool->getLeasedCount())->toBe(0);
});

it('drops a pinned connection that loses its connection and retries the statement on a fresh one', function () {
    $lost = false;

    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], function (string $sql, PDO $pdo) use (&$lost) {
        nativeLeaseMarkDirty()($sql, $pdo);

        if ($sql === 'boom' && ! $lost) {
            $lost = true;

            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    });

    $result = async(function () use ($connection) {
        $connection->unprepared('a dirty');
        $result = $connection->unprepared('boom');
        $connection->unprepared('a after');

        return $result;
    })->await(new TimeoutCancellation(5));

    expect($result)->toBeTrue()
        ->and($pdos[1]->log)->toBe(['a dirty'])
        ->and($pdos[2]->log)->toBe(['boom', 'a after'])
        ->and($pool->isLeased($pdos[1]))->toBeFalse()
        ->and($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool->getLeasedCount())->toBe(0);
});

it('hands a fiber waiting on a pool of one a fresh connection when a dirty lessee lets go', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(1, [], nativeLeaseMarkDirty());

    $a = async(fn () => $connection->unprepared('sleep 0.02 dirty'));

    $b = async(function () use ($connection) {
        delay(0.005);
        $connection->unprepared('b');

        return $connection->getPdo()->id;
    });

    delay(0.01);
    $waitingWhileDirtyHeld = $pool->getWaitingCount();
    $countWhileDirtyHeld = count($pool);

    [, $pdoOfB] = await([$a, $b], new TimeoutCancellation(5));

    expect($waitingWhileDirtyHeld)->toBe(1)
        ->and($countWhileDirtyHeld)->toBe(1)
        ->and($pdoOfB)->toBe(2)
        ->and($pdos)->toHaveCount(2)
        ->and($pdos[1]->log)->toBe(['sleep 0.02 dirty'])
        ->and($pdos[2]->log)->toBe(['b'])
        ->and($pool)->toHaveCount(1)
        ->and($pool->getWaitingCount())->toBe(0)
        ->and($pool->getLeasedCount())->toBe(0);
});

it('always discards a pooled PDO that is not a GuardedPdo, since it cannot report what ran on it', function () {
    $pdos = new ArrayObject;

    $pool = new NativePdoPool(function () use ($pdos) {
        $pdo = new class(count($pdos) + 1) extends PDO
        {
            /** @var list<string> */
            public array $log = [];

            public function __construct(public int $id) {}

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->log[] = $query;

                return nativeLeaseFakeStatement([], new LeaseGuard);
            }

            public function exec(string $statement): int|false
            {
                $this->log[] = $statement;

                return 0;
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };
        $pdos[$pdo->id] = $pdo;

        return $pdo;
    }, 2, 60.0);

    $connection = new NativeMariaDbConnection($pool, 'native_lease', '', [
        'name' => 'native-lease',
        'driver' => 'fledge-mariadb-native',
    ]);

    // Pinned to its owner like any dirty lease: the main context keeps its own.
    $connection->unprepared('select 1');
    $connection->select('select 2');
    async(fn () => $connection->unprepared('select 3'))->await();
    async(fn () => $connection->unprepared('select 4'))->await();

    $pinned = $pool->getLeasedCount();

    $connection->disconnect();

    expect($pdos)->toHaveCount(3)
        ->and($pdos[1]->log)->toBe(['select 1', 'select 2'])
        ->and($pdos[2]->log)->toBe(['select 3'])
        ->and($pdos[3]->log)->toBe(['select 4'])
        ->and($pinned)->toBe(1)
        ->and($pool)->toHaveCount(0)
        ->and($pool->getIdleCount())->toBe(0)
        ->and($pool->getLeasedCount())->toBe(0);
});

it('discards a dirty lease on the idle destructor path', function () {
    [$connection, $pool, $pdos] = nativeLeaseSetup(2, [], nativeLeaseMarkDirty());

    // A scope destroyed with no open scope or transaction: only the dirty flag decides.
    $pdo = $pool->acquire();
    $pdo->leaseGuard()->markSessionChanged();

    $scope = new LeaseScope;
    $scope->leases['write'] = new Lease($pool, $pdo);

    unset($scope);

    expect($pool->isLeased($pdo))->toBeFalse()
        ->and($pool)->toHaveCount(0)
        ->and($pool->getIdleCount())->toBe(0);

    // The same through a fiber that ends with a raw PDO it dirtied still leased.
    async(function () use ($connection) {
        $connection->getPdo()->exec('dirty raw');
    })->await();

    expect($pdos)->toHaveCount(2)
        ->and($pdos[2]->log)->toBe(['dirty raw'])
        ->and($pool->isLeased($pdos[2]))->toBeFalse()
        ->and($pool)->toHaveCount(0)
        ->and($pool->getLeasedCount())->toBe(0);

    $connection->unprepared('next');

    expect($pdos)->toHaveCount(3)
        ->and($pdos[3]->log)->toBe(['next'])
        ->and($pool->getIdleCount())->toBe(1);
});
