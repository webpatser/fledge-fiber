<?php

use Fledge\Async\DeferredFuture;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * End-to-end concurrency of the native drivers through a DatabaseManager wired
 * like an app (what DB::connection() resolves), against real MySQL (13306) and
 * MariaDB (13307) with pdo_mysql made fiber-aware by fiberio:
 * php -d extension=<path>/fiberio.so vendor/bin/pest <this file>
 *
 * Pool sizing against the pool cap, KILL reconnecting only the killed fiber on
 * an idle connection, and the lease owner assertion are covered in
 * NativeConnectionServerTest; this file covers what runs through the manager.
 */

/**
 * A stock blocking pdo_mysql connection for DDL and seeding.
 *
 * Lock waits are capped at 10 s: the DDL here blocks outside any fiber, so a
 * metadata lock held by another run against the same server (two suites at
 * once) would otherwise hang the process for the server default of a year.
 */
function nativeConcurrencyAdmin(string $flavor): PDO
{
    $config = $flavor === 'mysql' ? mysqlConfig() : mariadbConfig();

    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('SET SESSION lock_wait_timeout = 10, innodb_lock_wait_timeout = 10');

    return $pdo;
}

/**
 * Recreate the scratch table, seeded with $rows rows, and drop it after the test.
 */
function nativeConcurrencyTable(string $flavor, int $rows = 0): void
{
    if (($reason = nativeDriverSkipReason($flavor)) !== null) {
        test()->markTestSkipped($reason);
    }

    $admin = nativeConcurrencyAdmin($flavor);
    $admin->exec('DROP TABLE IF EXISTS _native_concurrency');
    $admin->exec('CREATE TABLE _native_concurrency (id INT PRIMARY KEY, label VARCHAR(20) NOT NULL) ENGINE=InnoDB');

    for ($i = 1; $i <= $rows; $i++) {
        $admin->exec("INSERT INTO _native_concurrency (id, label) VALUES ({$i}, 'row {$i}')");
    }

    test()->flavorsToClean = [...test()->flavorsToClean, $flavor];
}

beforeEach(function () {
    $this->flavorsToClean = [];
    $this->managers = [];
});

afterEach(function () {
    foreach ($this->managers ?? [] as $manager) {
        $manager->connection()->disconnect();
    }

    foreach (array_unique($this->flavorsToClean ?? []) as $flavor) {
        nativeConcurrencyAdmin($flavor)->exec('DROP TABLE IF EXISTS _native_concurrency');
    }

    nativeDriverReset();
});

it('resolves the native connection through the manager and runs 32 SLEEP(0.05) queries in under 0.2s', function (string $flavor) {
    $this->managers[] = $db = nativeDatabaseManager($flavor, ['pool_size' => 32]);

    expect($db->connection())->toBeInstanceOf($flavor === 'mysql' ? NativeMySqlConnection::class : NativeMariaDbConnection::class);

    $start = hrtime(true);

    $results = await(array_map(
        fn () => async(fn () => $db->connection()->select('SELECT SLEEP(0.05) AS slept')),
        range(1, 32),
    ));

    $elapsed = (hrtime(true) - $start) / 1e9;

    expect($results)->toHaveCount(32)
        ->and(array_values(array_unique(array_map(fn (array $rows) => (int) $rows[0]->slept, $results))))->toBe([0])
        // 32 sequential sleeps take 1.6s; one wave takes ~0.05s plus the connects.
        ->and($elapsed)->toBeLessThan(0.2)
        ->and($db->connection()->getRawPdo())->toBeInstanceOf(NativePdoPool::class)
        ->and(count($db->connection()->getRawPdo()))->toBeLessThanOrEqual(32)
        ->and($db->connection()->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('keeps concurrent DB::transaction calls in two fibers isolated and shows only committed rows', function (string $flavor) {
    nativeConcurrencyTable($flavor);
    $this->managers[] = $db = nativeDatabaseManager($flavor, ['pool_size' => 4]);

    $aInserted = new DeferredFuture;
    $bInserted = new DeferredFuture;

    $a = async(fn () => $db->connection()->transaction(function ($c) use ($aInserted, $bInserted) {
        $c->table('_native_concurrency')->insert(['id' => 1, 'label' => 'a']);
        $level = $c->transactionLevel();
        $aInserted->complete();
        $bInserted->getFuture()->await();

        // B inserted its row but has not committed: only A's own row is visible.
        return ['level' => $level, 'ids' => $c->table('_native_concurrency')->orderBy('id')->pluck('id')->all()];
    }));

    $b = async(function () use ($db, $aInserted, $bInserted) {
        $aInserted->getFuture()->await();

        return $db->connection()->transaction(function ($c) use ($bInserted) {
            // A inserted its row but has not committed: nothing is visible yet.
            $seen = $c->table('_native_concurrency')->pluck('id')->all();
            $c->table('_native_concurrency')->insert(['id' => 2, 'label' => 'b']);
            $level = $c->transactionLevel();
            $bInserted->complete();
            delay(0.05);

            return ['level' => $level, 'seen' => $seen];
        });
    });

    [$ra, $rb] = await([$a, $b]);

    expect($ra)->toBe(['level' => 1, 'ids' => [1]])
        ->and($rb)->toBe(['level' => 1, 'seen' => []])
        ->and($db->connection()->transactionLevel())->toBe(0)
        ->and($db->connection()->table('_native_concurrency')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
        ->and($db->connection()->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('rolls back one fiber\'s transaction without touching the transaction of the other', function (string $flavor) {
    nativeConcurrencyTable($flavor);
    $this->managers[] = $db = nativeDatabaseManager($flavor, ['pool_size' => 4]);

    $aInserted = new DeferredFuture;
    $bInserted = new DeferredFuture;
    $aRolledBack = new DeferredFuture;

    $a = async(function () use ($db, $aInserted, $bInserted, $aRolledBack) {
        try {
            $db->connection()->transaction(function ($c) use ($aInserted, $bInserted) {
                $c->table('_native_concurrency')->insert(['id' => 1, 'label' => 'a']);
                $aInserted->complete();
                $bInserted->getFuture()->await();

                throw new RuntimeException('abort a');
            });
        } catch (RuntimeException) {
            // expected
        } finally {
            $aRolledBack->complete();
        }

        return $db->connection()->transactionLevel();
    });

    $b = async(function () use ($db, $aInserted, $bInserted, $aRolledBack) {
        $aInserted->getFuture()->await();

        return $db->connection()->transaction(function ($c) use ($bInserted, $aRolledBack) {
            $c->table('_native_concurrency')->insert(['id' => 2, 'label' => 'b']);
            $bInserted->complete();
            $aRolledBack->getFuture()->await();

            // A's rollback must not have ended B's transaction or removed B's row.
            return ['level' => $c->transactionLevel(), 'ids' => $c->table('_native_concurrency')->pluck('id')->all()];
        });
    });

    [$levelA, $rb] = await([$a, $b]);

    expect($levelA)->toBe(0)
        ->and($rb)->toBe(['level' => 1, 'ids' => [2]])
        ->and($db->connection()->table('_native_concurrency')->pluck('id')->all())->toBe([2])
        ->and($db->connection()->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('iterates cursor() in one fiber while another fiber keeps querying', function (string $flavor) {
    nativeConcurrencyTable($flavor, 20);
    $this->managers[] = $db = nativeDatabaseManager($flavor, ['pool_size' => 2]);

    $events = [];

    $reader = async(function () use ($db, &$events) {
        $seen = 0;

        foreach ($db->connection()->table('_native_concurrency')->orderBy('id')->cursor() as $row) {
            $seen++;
            $events[] = 'cursor';
            delay(0.01);
        }

        return $seen;
    });

    $querier = async(function () use ($db, &$events) {
        delay(0.025);
        $counts = [];

        for ($i = 0; $i < 3; $i++) {
            $counts[] = $db->connection()->table('_native_concurrency')->count();
            $events[] = 'query';
            delay(0.01);
        }

        return $counts;
    });

    [$seen, $counts] = await([$reader, $querier]);

    $firstQuery = array_search('query', $events, true);
    $lastCursor = array_key_last(array_filter($events, fn (string $event) => $event === 'cursor'));

    expect($seen)->toBe(20)
        ->and($counts)->toBe([20, 20, 20])
        // The queries ran while the cursor was still open, not after it ended.
        ->and($firstQuery)->toBeLessThan($lastCursor)
        ->and($db->connection()->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('surfaces a lost connection inside a transaction and reconnects only that fiber', function (string $flavor) {
    $this->managers[] = $db = nativeDatabaseManager($flavor, ['pool_size' => 3]);
    $this->managers[] = $killerDb = nativeDatabaseManager($flavor, ['pool_size' => 1]);
    $killer = $killerDb->connection();

    $b = async(fn () => $db->connection()->transaction(function ($c) {
        $first = (int) $c->scalar('SELECT CONNECTION_ID()');
        delay(0.2);

        return [$first, (int) $c->scalar('SELECT CONNECTION_ID()'), $c->transactionLevel()];
    }));

    $a = async(function () use ($db, $killer) {
        delay(0.01);

        $before = null;
        $error = null;

        try {
            $db->connection()->transaction(function ($c) use ($killer, &$before) {
                $before = (int) $c->scalar('SELECT CONNECTION_ID()');
                $killer->statement("KILL {$before}");
                delay(0.05);

                // Inside a transaction there is no silent retry: the error reaches the caller.
                $c->scalar('SELECT 1');
            });
        } catch (Throwable $e) {
            $error = $e;
        }

        $levelAfterFailure = $db->connection()->transactionLevel();
        $after = (int) $db->connection()->scalar('SELECT CONNECTION_ID()');

        return [$before, $error, $levelAfterFailure, $after];
    });

    [[$bFirst, $bSecond, $bLevel], [$aBefore, $aError, $aLevel, $aAfter]] = await([$b, $a]);

    expect($aError)->toBeInstanceOf(Throwable::class)
        ->and((new LostConnectionDetector)->causedByLostConnection($aError))->toBeTrue()
        ->and($aLevel)->toBe(0)
        // The next query of that fiber runs on a fresh connection.
        ->and($aAfter)->not->toBe($aBefore)
        // B kept its own connection and its transaction through A's failure.
        ->and($bSecond)->toBe($bFirst)
        ->and($bFirst)->not->toBe($aBefore)
        ->and($bLevel)->toBe(1)
        ->and($db->connection()->transactionLevel())->toBe(0)
        ->and($db->connection()->getRawPdo()->getLeasedCount())->toBe(0);

    if ($aError instanceof QueryException) {
        // 2006 gone away, 2013 lost during query, 1317/1927 query or connection killed.
        expect($aError->errorInfo[1] ?? null)->toBeIn([2006, 2013, 1317, 1927]);
    }
})->with(['mariadb', 'mysql']);
