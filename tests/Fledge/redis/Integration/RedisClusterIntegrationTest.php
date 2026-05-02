<?php

use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Fiber\Redis\FledgeRedisClusterConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;

function clusterNodes(): array
{
    $nodes = test_env('FLEDGE_TEST_REDIS_CLUSTER_NODES', '');

    if ($nodes === '') {
        return [];
    }

    $parsed = [];

    foreach (explode(',', $nodes) as $entry) {
        $entry = trim($entry);

        if ($entry === '') {
            continue;
        }

        $colon = strrpos($entry, ':');

        if ($colon === false) {
            $parsed[] = ['host' => $entry, 'port' => 6379];

            continue;
        }

        $parsed[] = [
            'host' => substr($entry, 0, $colon),
            'port' => (int) substr($entry, $colon + 1),
        ];
    }

    return $parsed;
}

function clusterAvailable(): bool
{
    $nodes = clusterNodes();

    if ($nodes === []) {
        return false;
    }

    foreach ($nodes as $node) {
        $sock = @fsockopen($node['host'], $node['port'], $errno, $errstr, 1);

        if ($sock) {
            fclose($sock);

            return true;
        }
    }

    return false;
}

function clusterConnection(): FledgeRedisClusterConnection
{
    return (new FledgeRedisConnector)->connectToCluster(clusterNodes(), [], []);
}

uses()->beforeEach(function () {
    if (! clusterAvailable()) {
        $this->markTestSkipped('Set FLEDGE_TEST_REDIS_CLUSTER_NODES to run cluster integration tests.');
    }
});

it('round-trips set and get across slots', function () {
    $conn = clusterConnection();

    $keys = ['cluster:test:a', 'cluster:test:b', 'cluster:test:c', 'cluster:test:d'];

    foreach ($keys as $key) {
        $conn->set($key, 'value-of-'.$key);
    }

    foreach ($keys as $key) {
        expect($conn->get($key))->toBe('value-of-'.$key);
    }

    foreach ($keys as $key) {
        $conn->command('del', [$key]);
    }

    $conn->disconnect();
});

it('handles multi-key MGET when keys share a hash tag', function () {
    $conn = clusterConnection();

    $conn->set('{group1}.a', 'A');
    $conn->set('{group1}.b', 'B');

    $values = $conn->mget(['{group1}.a', '{group1}.b']);

    expect($values)->toBe(['A', 'B']);

    $conn->command('del', ['{group1}.a']);
    $conn->command('del', ['{group1}.b']);
    $conn->disconnect();
});

it('returns CROSSSLOT for multi-key commands without a hash tag', function () {
    $conn = clusterConnection();

    expect(fn () => $conn->mget(['cluster:no-tag:a', 'cluster:no-tag:b']))
        ->toThrow(QueryException::class);

    $conn->disconnect();
});

it('flushdb fans out across all masters', function () {
    $conn = clusterConnection();

    $conn->set('cluster:flush:1', 'x');
    $conn->set('cluster:flush:2', 'y');
    $conn->set('cluster:flush:3', 'z');

    $conn->flushdb();

    expect($conn->get('cluster:flush:1'))->toBeNull()
        ->and($conn->get('cluster:flush:2'))->toBeNull()
        ->and($conn->get('cluster:flush:3'))->toBeNull();

    $conn->disconnect();
});

it('evaluates a Lua script with all keys in the same slot', function () {
    $conn = clusterConnection();

    $conn->set('{evaltest}.k', 'lua-value');

    $result = $conn->eval(
        'return redis.call("GET", KEYS[1])',
        1,
        '{evaltest}.k',
    );

    expect($result)->toBe('lua-value');

    $conn->command('del', ['{evaltest}.k']);
    $conn->disconnect();
});

it('reports isCluster as true', function () {
    $conn = clusterConnection();

    expect($conn->isCluster())->toBeTrue();

    $conn->disconnect();
});

it('keys() fans out and merges results across masters', function () {
    $conn = clusterConnection();

    $conn->flushdb();

    $conn->set('keys:test:a', 'A');
    $conn->set('keys:test:b', 'B');
    $conn->set('keys:test:c', 'C');

    $found = $conn->keys('keys:test:*');

    sort($found);

    expect($found)->toBe(['keys:test:a', 'keys:test:b', 'keys:test:c']);

    $conn->flushdb();
    $conn->disconnect();
});

it('scan iterates a single node and exposes the node option', function () {
    $conn = clusterConnection();

    $conn->flushdb();

    $conn->set('scan:test:a', 'A');
    $conn->set('scan:test:b', 'B');
    $conn->set('scan:test:c', 'C');

    $found = [];

    foreach (clusterNodes() as $node) {
        $endpoint = $node['host'].':'.$node['port'];
        $cursor = '0';

        do {
            $result = $conn->scan($cursor, ['node' => $endpoint, 'match' => 'scan:test:*', 'count' => 100]);

            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;

            array_push($found, ...$keys);
        } while ($cursor !== '0');
    }

    sort($found);

    expect($found)->toBe(['scan:test:a', 'scan:test:b', 'scan:test:c']);

    $conn->flushdb();
    $conn->disconnect();
});

it('supports MULTI/EXEC when keys stay within one slot', function () {
    $conn = clusterConnection();

    $conn->multi();
    $conn->set('{txn}.a', 'first');
    $conn->set('{txn}.b', 'second');
    $exec = $conn->exec();

    expect($exec)->toBeArray()
        ->and($conn->get('{txn}.a'))->toBe('first')
        ->and($conn->get('{txn}.b'))->toBe('second');

    $conn->command('del', ['{txn}.a']);
    $conn->command('del', ['{txn}.b']);
    $conn->disconnect();
});
