<?php

use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
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
    return new class extends PDO
    {
        /** @var list<string> */
        public array $log = [];

        private bool $open = false;

        public function __construct() {}

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

it('keeps the level per fiber under Revolt scheduling', function () {
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

    // This test drives fibers by hand; a fiberio waiter would park them on Revolt.
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
