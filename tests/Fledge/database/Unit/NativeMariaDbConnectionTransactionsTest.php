<?php

use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\GuardedPdo;
use Fledge\Fiber\Database\Native\LeaseGuard;
use Fledge\Fiber\Database\Native\NativePdoPool;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Spike N1a: the inherited `$transactions` counter is redeclared as a property
 * hook backed by per-fiber state, so ManagesTransactions keeps its level per
 * fiber. The connection under test runs on a real NativePdoPool, so each
 * fiber's begin/commit/rollback hits its own leased handle.
 */

/**
 * PDO double that records transaction calls without a server.
 */
function nativeFakePdo(): PDO
{
    // Guarded, so the connection can see that nothing it ran changed the session.
    return new class extends PDO implements GuardedPdo
    {
        /** @var list<string> */
        public array $log = [];

        private bool $open = false;

        private LeaseGuard $guard;

        public function __construct()
        {
            $this->guard = new LeaseGuard;
        }

        public function leaseGuard(): LeaseGuard
        {
            return $this->guard;
        }

        public function beginTransaction(): bool
        {
            if ($this->open) {
                throw new PDOException('There is already an active transaction');
            }

            $this->open = true;
            $this->log[] = 'begin';

            return true;
        }

        public function commit(): bool
        {
            if (! $this->open) {
                throw new PDOException('There is no active transaction');
            }

            $this->open = false;
            $this->log[] = 'commit';

            return true;
        }

        public function rollBack(): bool
        {
            if (! $this->open) {
                throw new PDOException('There is no active transaction');
            }

            $this->open = false;
            $this->log[] = 'rollback';

            return true;
        }

        public function inTransaction(): bool
        {
            return $this->open;
        }

        public function exec(string $statement): int|false
        {
            $this->log[] = $statement;

            return 0;
        }
    };
}

/**
 * A NativeMariaDbConnection on a real NativePdoPool fed by $factory, so every
 * fiber (and the main context) leases its own PDO.
 */
function nativeTxConnection(Closure $factory): NativeMariaDbConnection
{
    return new NativeMariaDbConnection(new NativePdoPool($factory, 8, 60.0), 'spike', '', ['name' => 'native-spike']);
}

it('keeps the transaction level per fiber when two fibers interleave transaction()', function () {
    $connection = nativeTxConnection(nativeFakePdo(...));
    $levels = ['A' => [], 'B' => []];
    $pdos = [];

    $a = new Fiber(function () use ($connection, &$levels, &$pdos) {
        $pdos['A'] = $connection->getPdo();

        $connection->transaction(function ($c) use (&$levels) {
            $levels['A'][] = $c->transactionLevel();
            Fiber::suspend();

            $c->transaction(function ($c) use (&$levels) {
                $levels['A'][] = $c->transactionLevel();
                Fiber::suspend();
            });

            $levels['A'][] = $c->transactionLevel();
            Fiber::suspend();
        });

        $levels['A'][] = $connection->transactionLevel();
    });

    $b = new Fiber(function () use ($connection, &$levels, &$pdos) {
        $pdos['B'] = $connection->getPdo();

        $connection->transaction(function ($c) use (&$levels) {
            $levels['B'][] = $c->transactionLevel();
            Fiber::suspend();

            $levels['B'][] = $c->transactionLevel();
            Fiber::suspend();

            $levels['B'][] = $c->transactionLevel();
            Fiber::suspend();

            $levels['B'][] = $c->transactionLevel();
        });

        $levels['B'][] = $connection->transactionLevel();
    });

    $a->start();            // A: level 1, suspends inside the outer transaction
    $b->start();            // B: level 1 while A is open
    $a->resume();           // A: nested, level 2
    $b->resume();           // B: still 1 while A sits at 2
    $a->resume();           // A: nested committed, back to 1
    $b->resume();           // B: still 1
    $a->resume();           // A: commits, level 0
    expect($connection->transactionLevel())->toBe(0);
    $b->resume();           // B: still 1 after A committed, then commits

    expect($a->isTerminated())->toBeTrue()
        ->and($b->isTerminated())->toBeTrue()
        ->and($levels['A'])->toBe([1, 2, 1, 0])
        ->and($levels['B'])->toBe([1, 1, 1, 1, 0])
        ->and($pdos['A']->log)->toBe(['begin', 'SAVEPOINT trans2', 'commit'])
        ->and($pdos['B']->log)->toBe(['begin', 'commit'])
        ->and($connection->transactionLevel())->toBe(0);
});

it('does not let a rollback in one fiber touch the other fiber or the main context', function () {
    $connection = nativeTxConnection(nativeFakePdo(...));
    $connection->beginTransaction();

    $a = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        $connection->beginTransaction();
        Fiber::suspend($connection->transactionLevel());
        $connection->rollBack(0);

        return $connection->transactionLevel();
    });

    $b = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        Fiber::suspend($connection->transactionLevel());
        $connection->commit();

        return $connection->transactionLevel();
    });

    expect($a->start())->toBe(2)
        ->and($b->start())->toBe(1)
        ->and($connection->transactionLevel())->toBe(1);

    $a->resume();

    expect($a->getReturn())->toBe(0)
        ->and($connection->transactionLevel())->toBe(1);

    $b->resume();

    expect($b->getReturn())->toBe(0)
        ->and($connection->transactionLevel())->toBe(1);

    $connection->commit();

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->log)->toBe(['begin', 'commit']);
});

it('keeps the level per fiber under loop scheduling', function () {
    $connection = nativeTxConnection(nativeFakePdo(...));

    $worker = fn (string $name, float $pause) => async(function () use ($connection, $name, $pause) {
        $seen = [];

        $connection->transaction(function ($c) use (&$seen, $pause) {
            $seen[] = $c->transactionLevel();
            delay($pause);
            $seen[] = $c->transactionLevel();
            delay($pause);
            $seen[] = $c->transactionLevel();
        });

        $seen[] = $connection->transactionLevel();

        return [$name, $seen, $connection->getPdo()->log];
    });

    $results = await([$worker('A', 0.001), $worker('B', 0.002)]);

    foreach ($results as [$name, $seen, $log]) {
        expect($seen)->toBe([1, 1, 1, 0], "fiber {$name}")
            ->and($log)->toBe(['begin', 'commit'], "fiber {$name}");
    }

    expect($connection->transactionLevel())->toBe(0);
});

it('isolates concurrent fiber transactions on a real MariaDB server', function () {
    if (! extension_loaded('pdo_mysql')) {
        $this->markTestSkipped('pdo_mysql extension not loaded');
    }

    if (! mariadbAvailable()) {
        $this->markTestSkipped('MariaDB not available on port '.test_env('FLEDGE_TEST_MARIADB_PORT', 13307));
    }

    // This test drives fibers by hand; a fiberio waiter would park them on the loop.
    if (function_exists('FiberIo\enabled') && \FiberIo\enabled()) {
        \FiberIo\disable();
    }

    $config = mariadbConfig();
    $factory = fn (): PDO => new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    try {
        $factory();
    } catch (PDOException $e) {
        $this->markTestSkipped('MariaDB connection could not be established: '.$e->getMessage());
    }

    $connection = nativeTxConnection($factory);

    $connection->statement('DROP TABLE IF EXISTS native_tx_spike');
    $connection->statement('CREATE TABLE native_tx_spike (id INT AUTO_INCREMENT PRIMARY KEY, who VARCHAR(8) NOT NULL) ENGINE=InnoDB');

    try {
        $levels = ['A' => [], 'B' => []];

        $a = new Fiber(function () use ($connection, &$levels) {
            $connection->transaction(function ($c) use (&$levels) {
                $c->insert('INSERT INTO native_tx_spike (who) VALUES (?)', ['A']);
                $levels['A'][] = $c->transactionLevel();
                Fiber::suspend();

                $c->insert('INSERT INTO native_tx_spike (who) VALUES (?)', ['A2']);
                $levels['A'][] = $c->transactionLevel();
            });

            $levels['A'][] = $connection->transactionLevel();
        });

        $b = new Fiber(function () use ($connection, &$levels) {
            $connection->beginTransaction();
            $connection->insert('INSERT INTO native_tx_spike (who) VALUES (?)', ['B']);
            $levels['B'][] = $connection->transactionLevel();
            Fiber::suspend();

            $levels['B'][] = $connection->transactionLevel();
            $connection->rollBack();
            $levels['B'][] = $connection->transactionLevel();
        });

        $a->start();
        $b->start();
        $a->resume();   // A commits while B still holds its own open transaction
        $b->resume();   // B rolls back; A's rows must survive

        $rows = array_map(
            fn ($row) => $row->who,
            $connection->select('SELECT who FROM native_tx_spike ORDER BY id'),
        );

        expect($levels['A'])->toBe([1, 1, 0])
            ->and($levels['B'])->toBe([1, 1, 0])
            ->and($rows)->toBe(['A', 'A2'])
            ->and($connection->transactionLevel())->toBe(0);
    } finally {
        $connection->statement('DROP TABLE IF EXISTS native_tx_spike');
    }
});

/**
 * A native connection on a fake pool with a real event dispatcher and a
 * whenTransactionRunsLongerThan() handler that records every call.
 *
 * @return array{0: NativeMariaDbConnection, 1: ArrayObject<int, array{0: mixed, 1: object, 2: float}>}
 */
function nativeSlowTxConnection(mixed $threshold): array
{
    $connection = nativeTxConnection(nativeFakePdo(...));
    $connection->setEventDispatcher(new Illuminate\Events\Dispatcher);

    $calls = new ArrayObject;

    $connection->whenTransactionRunsLongerThan($threshold, function ($connection, $event, $duration) use ($calls) {
        $calls[] = [$connection, $event, $duration];
    });

    return [$connection, $calls];
}

it('times overlapping fiber transactions from their own start', function () {
    [$connection, $calls] = nativeSlowTxConnection(40);

    $slow = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        Fiber::suspend();
        $connection->commit();
    });

    $fast = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        Fiber::suspend();
        $connection->commit();
    });

    $slow->start();         // slow begins at t0
    usleep(60_000);
    $fast->start();         // fast begins at t60, while slow is still open
    usleep(10_000);
    $slow->resume();        // slow commits after ~70 ms
    $fast->resume();        // fast commits after ~10 ms

    expect($slow->isTerminated())->toBeTrue()
        ->and($fast->isTerminated())->toBeTrue()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0][0])->toBe($connection)
        ->and($calls[0][1])->toBeInstanceOf(Illuminate\Database\Events\TransactionCommitted::class)
        ->and($calls[0][2])->toBeGreaterThanOrEqual(69.0);
});

it('times a slow fiber transaction that rolls back while another fiber commits', function () {
    [$connection, $calls] = nativeSlowTxConnection(40);

    $slow = new Fiber(function () use ($connection) {
        try {
            $connection->transaction(function () {
                Fiber::suspend();

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }
    });

    $fast = new Fiber(function () use ($connection) {
        $connection->beginTransaction();
        Fiber::suspend();
        $connection->rollBack();
    });

    $slow->start();
    usleep(60_000);
    $fast->start();
    usleep(10_000);
    $fast->resume();        // fast rolls back after ~10 ms: below the threshold
    $slow->resume();        // slow rolls back after ~70 ms

    expect($slow->isTerminated())->toBeTrue()
        ->and($fast->isTerminated())->toBeTrue()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBeInstanceOf(Illuminate\Database\Events\TransactionRolledBack::class)
        ->and($calls[0][2])->toBeGreaterThanOrEqual(69.0);
});

it('times a nested transaction from the outer begin to the outer commit in one context', function () {
    [$connection, $calls] = nativeSlowTxConnection(Carbon\CarbonInterval::milliseconds(20));

    $connection->beginTransaction();
    usleep(15_000);
    $connection->beginTransaction();    // savepoint: does not restart the clock
    usleep(15_000);
    $connection->commit();              // back to level 1: no call yet

    expect($calls)->toHaveCount(0);

    $connection->beginTransaction();
    $connection->rollBack();            // savepoint rollback: still level 1

    expect($calls)->toHaveCount(0);

    $connection->commit();

    expect($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBeInstanceOf(Illuminate\Database\Events\TransactionCommitted::class)
        ->and($calls[0][2])->toBeGreaterThanOrEqual(29.0);

    // A fast transaction afterwards stays below the threshold.
    $connection->transaction(fn () => null);

    expect($calls)->toHaveCount(1);
});

it('starts a fresh clock for every transaction of one fiber and ignores other connections', function () {
    [$connection, $calls] = nativeSlowTxConnection(20);
    [$other] = nativeSlowTxConnection(0);
    $other->setEventDispatcher($connection->getEventDispatcher());

    $fiber = new Fiber(function () use ($connection, $other) {
        $connection->beginTransaction();
        usleep(30_000);
        $connection->rollBack();

        $other->beginTransaction();
        usleep(30_000);
        $other->commit();

        $connection->beginTransaction();
        $connection->commit();
    });

    $fiber->start();

    expect($fiber->isTerminated())->toBeTrue()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBeInstanceOf(Illuminate\Database\Events\TransactionRolledBack::class)
        ->and($calls[0][2])->toBeGreaterThanOrEqual(29.0);
});
