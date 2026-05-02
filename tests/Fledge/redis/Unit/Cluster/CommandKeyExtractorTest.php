<?php

use Fledge\Async\Redis\Cluster\CommandKeyExtractor;

it('returns null for topology commands', function (string $command) {
    expect(CommandKeyExtractor::extract($command, ['arg']))->toBeNull();
})->with(['PING', 'INFO', 'CLUSTER', 'CONFIG', 'SCAN', 'FLUSHDB', 'MULTI', 'EXEC']);

it('extracts the first argument for ordinary commands', function () {
    expect(CommandKeyExtractor::extract('GET', ['user:1']))->toBe(['user:1'])
        ->and(CommandKeyExtractor::extract('SET', ['user:1', 'value']))->toBe(['user:1'])
        ->and(CommandKeyExtractor::extract('HSET', ['hash', 'field', 'value']))->toBe(['hash'])
        ->and(CommandKeyExtractor::extract('ZADD', ['zset', 1, 'member']))->toBe(['zset']);
});

it('returns all arguments for multi-key commands', function () {
    expect(CommandKeyExtractor::extract('MGET', ['a', 'b', 'c']))->toBe(['a', 'b', 'c'])
        ->and(CommandKeyExtractor::extract('DEL', ['a', 'b']))->toBe(['a', 'b'])
        ->and(CommandKeyExtractor::extract('EXISTS', ['a']))->toBe(['a']);
});

it('returns even-indexed arguments for MSET', function () {
    expect(CommandKeyExtractor::extract('MSET', ['k1', 'v1', 'k2', 'v2']))->toBe(['k1', 'k2']);
});

it('skips the timeout argument for blocking pop commands', function () {
    expect(CommandKeyExtractor::extract('BLPOP', ['queue1', 'queue2', 5]))->toBe(['queue1', 'queue2'])
        ->and(CommandKeyExtractor::extract('BRPOP', ['q', 0]))->toBe(['q']);
});

it('returns the first two arguments for source/destination commands', function () {
    expect(CommandKeyExtractor::extract('RENAME', ['src', 'dst']))->toBe(['src', 'dst'])
        ->and(CommandKeyExtractor::extract('SMOVE', ['srcSet', 'dstSet', 'member']))->toBe(['srcSet', 'dstSet']);
});

it('extracts EVAL keys via the numkeys field', function () {
    $params = ['return KEYS[1]', 2, 'k1', 'k2', 'arg1'];

    expect(CommandKeyExtractor::extract('EVAL', $params))->toBe(['k1', 'k2'])
        ->and(CommandKeyExtractor::extract('EVALSHA', ['sha-hex', 1, 'onlykey']))->toBe(['onlykey']);
});

it('combines destination and member keys for ZUNIONSTORE', function () {
    $params = ['out', 2, 'src1', 'src2', 'WEIGHTS', 1, 1];

    expect(CommandKeyExtractor::extract('ZUNIONSTORE', $params))->toBe(['out', 'src1', 'src2']);
});

it('extracts XREAD stream keys after the STREAMS marker', function () {
    $params = ['COUNT', 10, 'STREAMS', 's1', 's2', 0, 0];

    expect(CommandKeyExtractor::extract('XREAD', $params))->toBe(['s1', 's2']);
});

it('returns an empty list when called with no parameters', function () {
    expect(CommandKeyExtractor::extract('GET', []))->toBe([]);
});
