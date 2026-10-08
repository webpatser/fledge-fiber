<?php

use Fledge\Fiber\Database\FiberDatabaseServiceProvider;
use Fledge\Fiber\Database\Native\FiberAwareTransactionsManager;
use Fledge\Fiber\Database\Native\GuardedPdo;
use Fledge\Fiber\Database\Native\LeaseGuard;
use Fledge\Fiber\Database\Native\LeaseOwnershipException;
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
                $this->open = false;
                $this->log[] = 'rollback';

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
