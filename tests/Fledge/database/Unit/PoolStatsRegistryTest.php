<?php

declare(strict_types=1);

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Database\SqlException;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Fledge\Fiber\Database\PoolStats;
use Fledge\Fiber\Database\PoolStatsRegistry;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * A real pool built the way the connector builds it. Building it opens no connection: the
 * factory runs on the first acquire().
 */
function registryTestPool(string $name): NativePdoPool
{
    $connector = new NativeMySqlConnector;
    $createPool = new ReflectionMethod($connector, 'createPool');

    return $createPool->invoke($connector, [
        'name' => $name,
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'none',
        'username' => 'none',
        'password' => '',
    ]);
}

/**
 * @return list<PoolStats>
 */
function registryPoolsNamed(string $name): array
{
    $pools = [];

    foreach (PoolStatsRegistry::all() as $entry) {
        if ($entry['name'] === $name) {
            $pools[] = $entry['pool'];
        }
    }

    return $pools;
}

it('lists a live pool under its connection name', function () {
    $pool = registryTestPool('registry-live');

    expect(registryPoolsNamed('registry-live'))->toHaveCount(1)
        ->and(registryPoolsNamed('registry-live')[0])->toBe($pool)
        ->and($pool->count())->toBe(0);
});

it('drops the entry once the pool is collected', function () {
    $pool = registryTestPool('registry-gone');

    expect(registryPoolsNamed('registry-gone'))->toHaveCount(1);

    unset($pool);
    gc_collect_cycles();

    expect(registryPoolsNamed('registry-gone'))->toBe([]);
});

it('lists every pool that shares one connection name', function () {
    $write = registryTestPool('registry-shared');
    $read = registryTestPool('registry-shared');

    $pools = registryPoolsNamed('registry-shared');

    expect($pools)->toHaveCount(2)
        ->and($pools)->toContain($write)
        ->and($pools)->toContain($read);
});

it('leaves a closed pool out', function () {
    $open = registryTestPool('registry-closed');
    $closed = registryTestPool('registry-closed');
    $closed->close();

    expect(registryPoolsNamed('registry-closed'))->toBe([$open]);
});

it('counts every caller waiting behind an in-progress connect', function () {
    // A listener that never accepts: the TCP connect completes in the backlog and the MySQL
    // handshake then waits forever, so the first caller holds the connect and the others queue.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $pool = new MysqlConnectionPool(
        new MysqlConfig('127.0.0.1', $port, 'app', 'secret'),
        maxConnections: 1,
        waitTimeout: 0.3,
    );

    try {
        $callers = [];
        for ($i = 0; $i < 3; $i++) {
            $callers[] = async(static fn () => $pool->leaseConnection());
        }

        delay(0.05);

        // The first caller runs the connect; the other two wait on it.
        expect($pool->getWaitingCount())->toBe(2);

        foreach ($callers as $caller) {
            try {
                $caller->await();
            } catch (SqlException) {
                // The wait timeout ends every caller.
            }
        }

        expect($pool->getWaitingCount())->toBe(0);
    } finally {
        $pool->close();
        fclose($server);
    }
});
