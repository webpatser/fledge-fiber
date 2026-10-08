<?php

use Fledge\Fiber\Database\Native\LeaseOwnershipException;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Native\NativePdo;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Illuminate\Database\QueryException;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Native driver against real MySQL (13306) and MariaDB (13307) servers with
 * pdo_mysql made fiber-aware by fiberio. Run with
 * `php -d extension=<path>/fiberio.so vendor/bin/pest <this file>`.
 */

/**
 * Build a native connection through the real connector, or skip.
 */
function nativeServerConnection(string $flavor, int $poolSize): NativeMySqlConnection|NativeMariaDbConnection
{
    if (! extension_loaded('fiberio')) {
        test()->markTestSkipped('fiberio extension not loaded: run with php -d extension=<path>/fiberio.so');
    }

    if (! extension_loaded('pdo_mysql')) {
        test()->markTestSkipped('pdo_mysql extension not loaded');
    }

    $available = $flavor === 'mysql' ? mysqlAvailable() : mariadbAvailable();

    if (! $available) {
        test()->markTestSkipped("{$flavor} test server not reachable");
    }

    $config = ($flavor === 'mysql' ? mysqlConfig() : mariadbConfig()) + [
        'driver' => "fledge-{$flavor}-native",
        'name' => "native-{$flavor}",
        'prefix' => '',
        'collation' => 'utf8mb4_unicode_ci',
        'pool_size' => $poolSize,
        'pool_idle_timeout' => 60,
    ];

    $connector = $flavor === 'mysql' ? new NativeMySqlConnector : new NativeMariaDbConnector;
    $pool = $connector->connect($config);

    $connection = $flavor === 'mysql'
        ? new NativeMySqlConnection($pool, $config['database'], '', $config)
        : new NativeMariaDbConnection($pool, $config['database'], '', $config);

    try {
        $connection->scalar('SELECT 1');
    } catch (QueryException $e) {
        test()->markTestSkipped("{$flavor} connection failed: ".$e->getMessage());
    }

    return $connection;
}

afterEach(function () {
    if (function_exists('FiberIo\enabled') && \FiberIo\enabled()) {
        \FiberIo\disable();
    }
});

it('runs concurrent queries on leased connections and caps them at the pool size', function (string $flavor) {
    $connection = nativeServerConnection($flavor, 2);
    $pool = $connection->getRawPdo();

    expect($pool)->toBeInstanceOf(NativePdoPool::class);

    $start = hrtime(true);

    $ids = await(array_map(
        fn () => async(function () use ($connection) {
            $connection->select('SELECT SLEEP(0.1)');

            return (int) $connection->scalar('SELECT CONNECTION_ID()');
        }),
        range(1, 4),
    ));

    $elapsed = (hrtime(true) - $start) / 1e9;

    // Four 0.1s sleeps on two connections: two waves, not four, not one.
    expect($elapsed)->toBeGreaterThan(0.19)->toBeLessThan(0.35)
        ->and(count(array_unique($ids)))->toBeLessThanOrEqual(2)
        ->and($pool)->toHaveCount(2)
        ->and($pool->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('reconnects only the fiber whose connection was killed', function (string $flavor) {
    $connection = nativeServerConnection($flavor, 3);
    $killer = nativeServerConnection($flavor, 1);

    $b = async(function () use ($connection) {
        return $connection->transaction(function ($c) {
            $first = (int) $c->scalar('SELECT CONNECTION_ID()');
            delay(0.15);

            return [$first, (int) $c->scalar('SELECT CONNECTION_ID()')];
        });
    });

    $a = async(function () use ($connection, $killer) {
        delay(0.01);

        $before = (int) $connection->scalar('SELECT CONNECTION_ID()');
        $killer->statement("KILL {$before}");
        delay(0.05);

        // The idle connection reused here is the killed one: lost
        // connection, reconnect() drops it, the query retries on a fresh one.
        $after = (int) $connection->scalar('SELECT CONNECTION_ID()');

        return [$before, $after];
    });

    [[$bFirst, $bSecond], [$aBefore, $aAfter]] = await([$b, $a]);

    expect($aAfter)->not->toBe($aBefore)
        ->and($bSecond)->toBe($bFirst)
        ->and($bFirst)->not->toBe($aBefore)
        ->and($connection->transactionLevel())->toBe(0);
})->with(['mariadb', 'mysql']);

it('rejects another fiber using a leased PDO with LeaseOwnershipException, not the fiberio Error', function (string $flavor) {
    $connection = nativeServerConnection($flavor, 2);
    $box = [];

    $owner = async(function () use ($connection, &$box) {
        $box['pdo'] = $connection->getPdo();

        // Parked inside pdo_mysql on this PDO's socket.
        $connection->select('SELECT SLEEP(0.1)');
    });

    $intruder = async(function () use (&$box) {
        delay(0.02);

        try {
            $box['pdo']->query('SELECT 1');
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    });

    [, $error] = await([$owner, $intruder]);

    expect($box['pdo'])->toBeInstanceOf(NativePdo::class)
        ->and($error)->toBeInstanceOf(LeaseOwnershipException::class)
        ->and($connection->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);
