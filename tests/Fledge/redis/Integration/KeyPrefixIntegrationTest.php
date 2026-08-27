<?php

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;

/*
 * Prefix parity against a live redis (port 16379): a prefixed connection
 * writes physically prefixed keys that a raw connection can read, and when
 * phpredis is available the same operation matrix is replayed through
 * OPT_PREFIX to assert an identical physical keyspace.
 */

const PREFIX_TEST_DB = 9;
const PREFIX_TEST_PREFIX = 'prefix.';

function prefixTestParams(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379),
        'database' => PREFIX_TEST_DB,
    ];
}

function prefixTestAvailable(): bool
{
    $params = prefixTestParams();
    $sock = @fsockopen($params['host'], $params['port'], $errno, $errstr, 1);

    if (! $sock) {
        return false;
    }

    fclose($sock);

    return true;
}

function prefixedConnection(): FledgeRedisConnection
{
    return (new FledgeRedisConnector)->connect(prefixTestParams() + ['prefix' => PREFIX_TEST_PREFIX], []);
}

function rawConnection(): FledgeRedisConnection
{
    return (new FledgeRedisConnector)->connect(prefixTestParams(), []);
}

/**
 * Runs the shared operation matrix against a prefixed FledgeRedisConnection.
 */
function runFledgePrefixMatrix(FledgeRedisConnection $c): array
{
    $c->set('str', 'v');
    $c->command('mset', ['a', '1', 'b', '2']);
    $c->command('del', ['b']);

    $c->command('rpush', ['list', 'x']);
    $c->command('rename', ['list', 'list2']);

    $c->eval("return redis.call('set', KEYS[1], ARGV[1])", 1, 'ek', 'ev');

    $c->zadd('z1', 1, 'm1');
    $c->zadd('z2', 2, 'm2');
    $c->zunionstore('zdest', ['z1', 'z2']);

    $c->command('rpush', ['slist', 'b']);
    $c->command('rpush', ['slist', 'a']);
    $c->command('sort', ['slist', 'ALPHA', 'STORE', 'sdest']);

    $c->command('xadd', ['stream', '*', 'f', 'v']);

    $c->command('rpush', ['bl', 'x']);
    $blpop = $c->blpop('bl', 1);

    $c->set('key1', '1');
    $c->set('key2', '2');

    return [
        'blpop' => $blpop,
        'keys' => collect($c->keys('key*'))->sort()->values()->all(),
        'keys_double' => collect($c->keys(PREFIX_TEST_PREFIX.'key*'))->sort()->values()->all(),
        'object_encoding' => $c->command('object', ['encoding', 'str']),
        'scan_unprefixed_match' => prefixScanAll(fn ($cursor) => $c->scan($cursor, ['match' => 'key*', 'count' => 100])),
        'scan_physical_match' => prefixScanAll(fn ($cursor) => $c->scan($cursor, ['match' => PREFIX_TEST_PREFIX.'key*', 'count' => 100])),
    ];
}

/**
 * Runs the same matrix through phpredis with OPT_PREFIX.
 */
function runPhpredisPrefixMatrix(Redis $r): array
{
    $r->set('str', 'v');
    $r->mSet(['a' => '1', 'b' => '2']);
    $r->del('b');

    $r->rPush('list', 'x');
    $r->rename('list', 'list2');

    $r->eval("return redis.call('set', KEYS[1], ARGV[1])", ['ek', 'ev'], 1);

    $r->zAdd('z1', 1, 'm1');
    $r->zAdd('z2', 2, 'm2');
    $r->zUnionStore('zdest', ['z1', 'z2']);

    $r->rPush('slist', 'b');
    $r->rPush('slist', 'a');
    $r->sort('slist', ['alpha' => true, 'store' => 'sdest']);

    $r->xAdd('stream', '*', ['f' => 'v']);

    $r->rPush('bl', 'x');
    $blpop = $r->blPop(['bl'], 1);

    $r->set('key1', '1');
    $r->set('key2', '2');

    $scan = function (string $match) use ($r): array {
        $found = [];
        $cursor = null;

        do {
            $chunk = $r->scan($cursor, $match, 100);

            if (is_array($chunk)) {
                array_push($found, ...$chunk);
            }
        } while ($cursor > 0);

        sort($found);

        return $found;
    };

    return [
        'blpop' => $blpop,
        'keys' => collect($r->keys('key*'))->sort()->values()->all(),
        'keys_double' => collect($r->keys(PREFIX_TEST_PREFIX.'key*'))->sort()->values()->all(),
        'object_encoding' => $r->object('encoding', 'str'),
        'scan_unprefixed_match' => $scan('key*'),
        'scan_physical_match' => $scan(PREFIX_TEST_PREFIX.'key*'),
    ];
}

/**
 * Drains a Laravel-style scan closure returning [cursor, keys] tuples.
 */
function prefixScanAll(Closure $page): array
{
    $found = [];
    $cursor = '0';

    do {
        $result = $page($cursor);

        if ($result === false) {
            break;
        }

        [$cursor, $keys] = $result;
        array_push($found, ...$keys);
    } while ($cursor !== '0');

    sort($found);

    return $found;
}

function physicalKeyspace(FledgeRedisConnection $raw): array
{
    $keys = $raw->keys('*');
    sort($keys);

    return $keys;
}

uses()->beforeEach(function () {
    if (! prefixTestAvailable()) {
        $this->markTestSkipped('Redis not available on port '.test_env('FLEDGE_TEST_REDIS_PORT', 16379));
    }
});

it('writes physically prefixed keys that a raw connection sees', function () {
    $raw = rawConnection();
    $raw->command('flushdb');

    $prefixed = prefixedConnection();

    try {
        $prefixed->set('key', 'value');

        expect($raw->get(PREFIX_TEST_PREFIX.'key'))->toBe('value')
            ->and($raw->get('key'))->toBeNull()
            ->and($prefixed->get('key'))->toBe('value');
    } finally {
        $raw->command('flushdb');
        $prefixed->disconnect();
        $raw->disconnect();
    }
});

it('produces the same physical keyspace as phpredis OPT_PREFIX for the full matrix', function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('phpredis extension not loaded.');
    }

    $params = prefixTestParams();

    $raw = rawConnection();
    $raw->command('flushdb');

    $prefixed = prefixedConnection();
    $fledgeResults = runFledgePrefixMatrix($prefixed);
    $fledgeKeyspace = physicalKeyspace($raw);

    $raw->command('flushdb');

    $phpredis = new Redis;
    $phpredis->connect($params['host'], $params['port']);
    $phpredis->select(PREFIX_TEST_DB);
    $phpredis->setOption(Redis::OPT_PREFIX, PREFIX_TEST_PREFIX);

    try {
        $phpredisResults = runPhpredisPrefixMatrix($phpredis);
        $phpredisKeyspace = physicalKeyspace($raw);

        expect($fledgeKeyspace)->toBe($phpredisKeyspace)
            ->and($fledgeKeyspace)->toContain(PREFIX_TEST_PREFIX.'str')
            // phpredis does not prefix the SORT STORE destination; neither do we.
            ->and($fledgeKeyspace)->toContain('sdest')
            ->and($fledgeResults['keys'])->toBe($phpredisResults['keys'])
            // keys($prefix.'*') is double-prefixed on both sides and matches nothing.
            ->and($fledgeResults['keys_double'])->toBe($phpredisResults['keys_double'])
            ->and($fledgeResults['keys_double'])->toBe([])
            ->and($fledgeResults['object_encoding'])->toBe($phpredisResults['object_encoding'])
            // SCAN MATCH is not auto-prefixed by default on either side.
            ->and($fledgeResults['scan_unprefixed_match'])->toBe($phpredisResults['scan_unprefixed_match'])
            ->and($fledgeResults['scan_unprefixed_match'])->toBe([])
            ->and($fledgeResults['scan_physical_match'])->toBe($phpredisResults['scan_physical_match'])
            ->and($fledgeResults['scan_physical_match'])->toBe([PREFIX_TEST_PREFIX.'key1', PREFIX_TEST_PREFIX.'key2'])
            // BLPOP returns the physical (prefixed) key name on both sides.
            ->and($fledgeResults['blpop'])->toBe($phpredisResults['blpop']);
    } finally {
        $raw->command('flushdb');
        $phpredis->close();
        $prefixed->disconnect();
        $raw->disconnect();
    }
});
