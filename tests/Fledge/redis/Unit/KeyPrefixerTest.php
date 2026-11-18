<?php

use Fledge\Async\Redis\Command\CommandKeySpec;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\KeyPrefixer;

final class KeyCapturingLink implements RedisLink
{
    /** @var list<array{string, array}> */
    public array $calls = [];

    public function __construct(private readonly int|string|array|null $response = 'OK')
    {
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $this->calls[] = [$command, $parameters];

        return new RedisValue($this->response);
    }
}

it('prefixes key positions with phpredis OPT_PREFIX semantics', function (string $command, array $args, array $expected) {
    $prefixer = new KeyPrefixer('prefix.');

    expect($prefixer->apply($command, $args))->toBe($expected);
})->with([
    'GET' => ['GET', ['key'], ['prefix.key']],
    'SET' => ['SET', ['key', 'value'], ['prefix.key', 'value']],
    'DEL' => ['DEL', ['a', 'b'], ['prefix.a', 'prefix.b']],
    'MGET' => ['MGET', ['a', 'b'], ['prefix.a', 'prefix.b']],
    'MSET' => ['MSET', ['a', '1', 'b', '2'], ['prefix.a', '1', 'prefix.b', '2']],
    'BLPOP keys but not timeout' => ['BLPOP', ['a', 'b', 5], ['prefix.a', 'prefix.b', 5]],
    'RENAME both keys' => ['RENAME', ['old', 'new'], ['prefix.old', 'prefix.new']],
    'EVAL numkeys range only' => [
        'EVAL',
        ['return 1', 2, 'k1', 'k2', 'argv1'],
        ['return 1', 2, 'prefix.k1', 'prefix.k2', 'argv1'],
    ],
    'EVALSHA numkeys range only' => [
        'EVALSHA',
        ['sha', 1, 'k1', 'argv1'],
        ['sha', 1, 'prefix.k1', 'argv1'],
    ],
    'ZUNIONSTORE destination and sources' => [
        'ZUNIONSTORE',
        ['dest', 2, 'a', 'b', 'WEIGHTS', 1, 2],
        ['prefix.dest', 2, 'prefix.a', 'prefix.b', 'WEIGHTS', 1, 2],
    ],
    'XREAD STREAMS keys but not ids' => [
        'XREAD',
        ['COUNT', 10, 'STREAMS', 's1', 's2', '0-0', '0-0'],
        ['COUNT', 10, 'STREAMS', 'prefix.s1', 'prefix.s2', '0-0', '0-0'],
    ],
    'SCAN MATCH untouched by default like phpredis' => [
        'SCAN',
        [0, 'MATCH', 'key*', 'COUNT', 100],
        [0, 'MATCH', 'key*', 'COUNT', 100],
    ],
    'KEYS pattern prefixed' => ['KEYS', ['key*'], ['prefix.key*']],
    'HSCAN key only, MATCH targets fields' => [
        'HSCAN',
        ['hash', 0, 'MATCH', 'field*'],
        ['prefix.hash', 0, 'MATCH', 'field*'],
    ],
    'SSCAN key only' => ['SSCAN', ['set', 0, 'MATCH', 'member*'], ['prefix.set', 0, 'MATCH', 'member*']],
    'ZSCAN key only' => ['ZSCAN', ['zset', 0, 'MATCH', 'member*'], ['prefix.zset', 0, 'MATCH', 'member*']],
    'SORT source only, STORE untouched like phpredis' => [
        'SORT',
        ['list', 'ALPHA', 'STORE', 'dest'],
        ['prefix.list', 'ALPHA', 'STORE', 'dest'],
    ],
    'OBJECT subcommand key' => ['OBJECT', ['ENCODING', 'key'], ['ENCODING', 'prefix.key']],
    'GETEX key' => ['GETEX', ['key', 'EX', 10], ['prefix.key', 'EX', 10]],
    'SUBSCRIBE channels' => ['SUBSCRIBE', ['c1', 'c2'], ['prefix.c1', 'prefix.c2']],
    'PSUBSCRIBE patterns' => ['PSUBSCRIBE', ['news.*'], ['prefix.news.*']],
    'UNSUBSCRIBE channels' => ['UNSUBSCRIBE', ['c1'], ['prefix.c1']],
    'PUBLISH channel but not message' => ['PUBLISH', ['chan', 'payload'], ['prefix.chan', 'payload']],
    'PING untouched' => ['PING', [], []],
    'FLUSHDB untouched' => ['FLUSHDB', ['ASYNC'], ['ASYNC']],
]);

/*
 * Key positions as Redis reports them through COMMAND GETKEYS, which is also
 * what phpredis OPT_PREFIX rewrites for these commands. Each row is checked
 * against both the prefixer and the cluster slot key extraction.
 */
dataset('numkeys and multi-key shapes', [
    'BITOP destination and sources' => [
        'BITOP',
        ['AND', 'dest', 'a', 'b'],
        ['AND', 'prefix.dest', 'prefix.a', 'prefix.b'],
        ['dest', 'a', 'b'],
    ],
    'BITOP NOT' => ['BITOP', ['NOT', 'dest', 'src'], ['NOT', 'prefix.dest', 'prefix.src'], ['dest', 'src']],
    'LMPOP numkeys first' => [
        'LMPOP',
        [2, 'a', 'b', 'LEFT', 'COUNT', 3],
        [2, 'prefix.a', 'prefix.b', 'LEFT', 'COUNT', 3],
        ['a', 'b'],
    ],
    'ZMPOP numkeys first' => ['ZMPOP', [1, 'z', 'MIN'], [1, 'prefix.z', 'MIN'], ['z']],
    'SINTERCARD numkeys first' => [
        'SINTERCARD',
        [2, 'a', 'b', 'LIMIT', 5],
        [2, 'prefix.a', 'prefix.b', 'LIMIT', 5],
        ['a', 'b'],
    ],
    'ZINTERCARD numkeys first' => ['ZINTERCARD', [1, 'z', 'LIMIT', 5], [1, 'prefix.z', 'LIMIT', 5], ['z']],
    'ZUNION numkeys first' => [
        'ZUNION',
        [2, 'a', 'b', 'WEIGHTS', 1, 2, 'WITHSCORES'],
        [2, 'prefix.a', 'prefix.b', 'WEIGHTS', 1, 2, 'WITHSCORES'],
        ['a', 'b'],
    ],
    'ZINTER numkeys first' => ['ZINTER', [2, 'a', 'b'], [2, 'prefix.a', 'prefix.b'], ['a', 'b']],
    'ZDIFF numkeys first' => ['ZDIFF', [2, 'a', 'b'], [2, 'prefix.a', 'prefix.b'], ['a', 'b']],
    'ZRANGESTORE destination and source' => [
        'ZRANGESTORE',
        ['dst', 'src', 0, -1],
        ['prefix.dst', 'prefix.src', 0, -1],
        ['dst', 'src'],
    ],
    'BLMPOP timeout and direction untouched' => [
        'BLMPOP',
        [5, 2, 'a', 'b', 'RIGHT', 'COUNT', 2],
        [5, 2, 'prefix.a', 'prefix.b', 'RIGHT', 'COUNT', 2],
        ['a', 'b'],
    ],
    'BZMPOP timeout and direction untouched' => [
        'BZMPOP',
        [0.5, 1, 'z', 'MAX'],
        [0.5, 1, 'prefix.z', 'MAX'],
        ['z'],
    ],
    'SUBSTR single key, offsets untouched' => ['SUBSTR', ['k', 0, 5], ['prefix.k', 0, 5], ['k']],
    'SORT_RO source only' => ['SORT_RO', ['list', 'BY', 'w_*'], ['prefix.list', 'BY', 'w_*'], ['list']],
    'MIGRATE single key, host untouched' => [
        'MIGRATE',
        ['10.0.0.1', 6379, 'k', 0, 5000, 'COPY'],
        ['10.0.0.1', 6379, 'prefix.k', 0, 5000, 'COPY'],
        ['k'],
    ],
    'MIGRATE KEYS option after AUTH named KEYS' => [
        'MIGRATE',
        ['10.0.0.1', 6379, '', 0, 5000, 'REPLACE', 'AUTH', 'KEYS', 'KEYS', 'a', 'b'],
        ['10.0.0.1', 6379, '', 0, 5000, 'REPLACE', 'AUTH', 'KEYS', 'KEYS', 'prefix.a', 'prefix.b'],
        ['a', 'b'],
    ],
    'MIGRATE KEYS option after AUTH2' => [
        'MIGRATE',
        ['h', 6379, '', 0, 5000, 'AUTH2', 'user', 'pw', 'KEYS', 'a'],
        ['h', 6379, '', 0, 5000, 'AUTH2', 'user', 'pw', 'KEYS', 'prefix.a'],
        ['a'],
    ],
    'XGROUP CREATE key after subcommand' => [
        'XGROUP',
        ['CREATE', 's', 'g', '$', 'MKSTREAM'],
        ['CREATE', 'prefix.s', 'g', '$', 'MKSTREAM'],
        ['s'],
    ],
    'XGROUP SETID' => ['XGROUP', ['SETID', 's', 'g', '0'], ['SETID', 'prefix.s', 'g', '0'], ['s']],
    'XGROUP DESTROY' => ['XGROUP', ['DESTROY', 's', 'g'], ['DESTROY', 'prefix.s', 'g'], ['s']],
    'XGROUP CREATECONSUMER' => ['XGROUP', ['CREATECONSUMER', 's', 'g', 'c'], ['CREATECONSUMER', 'prefix.s', 'g', 'c'], ['s']],
    'XGROUP DELCONSUMER' => ['XGROUP', ['delconsumer', 's', 'g', 'c'], ['delconsumer', 'prefix.s', 'g', 'c'], ['s']],
    'XGROUP HELP keyless' => ['XGROUP', ['HELP'], ['HELP'], []],
    'XINFO STREAM' => ['XINFO', ['STREAM', 's', 'FULL'], ['STREAM', 'prefix.s', 'FULL'], ['s']],
    'XINFO GROUPS' => ['XINFO', ['GROUPS', 's'], ['GROUPS', 'prefix.s'], ['s']],
    'XINFO CONSUMERS' => ['XINFO', ['CONSUMERS', 's', 'g'], ['CONSUMERS', 'prefix.s', 'g'], ['s']],
    'XINFO HELP keyless' => ['XINFO', ['HELP'], ['HELP'], []],
    'GEORADIUS STORE destination' => [
        'GEORADIUS',
        ['geo', 15, 37, 200, 'km', 'COUNT', 5, 'ANY', 'ASC', 'STORE', 'dst'],
        ['prefix.geo', 15, 37, 200, 'km', 'COUNT', 5, 'ANY', 'ASC', 'STORE', 'prefix.dst'],
        ['geo', 'dst'],
    ],
    'GEORADIUS STOREDIST destination' => [
        'GEORADIUS',
        ['geo', 15, 37, 200, 'km', 'STOREDIST', 'dst'],
        ['prefix.geo', 15, 37, 200, 'km', 'STOREDIST', 'prefix.dst'],
        ['geo', 'dst'],
    ],
    'GEORADIUSBYMEMBER member named store, STOREDIST destination' => [
        'GEORADIUSBYMEMBER',
        ['geo', 'store', 100, 'km', 'STOREDIST', 'dst'],
        ['prefix.geo', 'store', 100, 'km', 'STOREDIST', 'prefix.dst'],
        ['geo', 'dst'],
    ],
    'GEORADIUS_RO source only' => [
        'GEORADIUS_RO',
        ['geo', 15, 37, 200, 'km', 'WITHDIST'],
        ['prefix.geo', 15, 37, 200, 'km', 'WITHDIST'],
        ['geo'],
    ],
    'GEORADIUSBYMEMBER_RO source only' => [
        'GEORADIUSBYMEMBER_RO',
        ['geo', 'm', 100, 'km'],
        ['prefix.geo', 'm', 100, 'km'],
        ['geo'],
    ],
    'GEOSEARCHSTORE destination and source' => [
        'GEOSEARCHSTORE',
        ['dst', 'src', 'FROMMEMBER', 'm', 'BYRADIUS', 10, 'km', 'STOREDIST'],
        ['prefix.dst', 'prefix.src', 'FROMMEMBER', 'm', 'BYRADIUS', 10, 'km', 'STOREDIST'],
        ['dst', 'src'],
    ],
    'OBJECT ENCODING routes by key' => ['OBJECT', ['ENCODING', 'k'], ['ENCODING', 'prefix.k'], ['k']],
    'OBJECT FREQ routes by key' => ['OBJECT', ['FREQ', 'k'], ['FREQ', 'prefix.k'], ['k']],
    'OBJECT IDLETIME routes by key' => ['OBJECT', ['IDLETIME', 'k'], ['IDLETIME', 'prefix.k'], ['k']],
    'OBJECT REFCOUNT routes by key' => ['OBJECT', ['refcount', 'k'], ['refcount', 'prefix.k'], ['k']],
    'OBJECT HELP keyless' => ['OBJECT', ['HELP'], ['HELP'], []],
    'MEMORY USAGE routes by key' => [
        'MEMORY',
        ['USAGE', 'k', 'SAMPLES', 5],
        ['USAGE', 'prefix.k', 'SAMPLES', 5],
        ['k'],
    ],
    'MEMORY STATS keyless' => ['MEMORY', ['STATS'], ['STATS'], []],
    'XREADGROUP group named streams' => [
        'XREADGROUP',
        ['GROUP', 'streams', 'c', 'COUNT', 1, 'STREAMS', 's1', '>'],
        ['GROUP', 'streams', 'c', 'COUNT', 1, 'STREAMS', 'prefix.s1', '>'],
        ['s1'],
    ],
    'XREADGROUP consumer named STREAMS with options' => [
        'XREADGROUP',
        ['GROUP', 'g', 'STREAMS', 'BLOCK', 0, 'NOACK', 'STREAMS', 'a', 'b', '>', '>'],
        ['GROUP', 'g', 'STREAMS', 'BLOCK', 0, 'NOACK', 'STREAMS', 'prefix.a', 'prefix.b', '>', '>'],
        ['a', 'b'],
    ],
    'XREAD options before STREAMS' => [
        'XREAD',
        ['COUNT', 2, 'BLOCK', 0, 'STREAMS', 'a', '0-0'],
        ['COUNT', 2, 'BLOCK', 0, 'STREAMS', 'prefix.a', '0-0'],
        ['a'],
    ],
]);

it('prefixes only the Redis key positions of numkeys and multi-key commands', function (string $command, array $args, array $prefixed, array $keys) {
    expect((new KeyPrefixer('prefix.'))->apply($command, $args))->toBe($prefixed);
})->with('numkeys and multi-key shapes');

it('extracts only the Redis key positions for cluster slot routing', function (string $command, array $args, array $prefixed, array $keys) {
    expect(CommandKeySpec::extract($command, $args))->toBe($keys)
        ->and(CommandKeySpec::extract(strtolower($command), $args))->toBe($keys);
})->with('numkeys and multi-key shapes');

it('prefixes the SCAN MATCH value when scan prefixing is enabled', function () {
    $prefixer = new KeyPrefixer('prefix.', scanPrefix: true);

    expect($prefixer->apply('SCAN', [0, 'MATCH', 'key*', 'COUNT', 100]))
        ->toBe([0, 'MATCH', 'prefix.key*', 'COUNT', 100]);
});

it('is a no-op with an empty prefix', function () {
    $prefixer = new KeyPrefixer('');

    expect($prefixer->apply('GET', ['key']))->toBe(['key'])
        ->and($prefixer->isActive())->toBeFalse();
});

it('prefixes an explicit eval key list', function () {
    $prefixer = new KeyPrefixer('prefix.');

    expect($prefixer->prefixKeys(['k1', 'k2']))->toBe(['prefix.k1', 'prefix.k2']);
});

it('prefixes commands issued through the connection', function () {
    $link = new KeyCapturingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link), prefix: 'prefix.');

    $connection->get('key');
    $connection->set('key', 'value');
    $connection->mget(['a', 'b']);

    expect($link->calls)->toBe([
        ['GET', ['prefix.key']],
        ['SET', ['prefix.key', 'value']],
        ['MGET', ['prefix.a', 'prefix.b']],
    ]);
});

it('prefixes only the keys of an eval issued through the connection', function () {
    $link = new KeyCapturingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link), prefix: 'prefix.');

    $connection->eval("return 1", 2, 'k1', 'k2', 'argv1');

    [$command, $parameters] = $link->calls[0];

    expect($command)->toBe('evalsha')
        ->and(array_slice($parameters, 1))->toBe([2, 'prefix.k1', 'prefix.k2', 'argv1']);
});

it('leaves executeRaw untouched', function () {
    $link = new KeyCapturingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link), prefix: 'prefix.');

    $connection->executeRaw(['GET', 'key']);

    expect($link->calls)->toBe([['GET', ['key']]]);
});

it('does not strip the prefix from returned scan keys', function () {
    $link = new KeyCapturingLink(['0', ['prefix.key1', 'prefix.key2']]);
    $connection = new FledgeRedisConnection(new RedisClient($link), prefix: 'prefix.');

    [$cursor, $keys] = $connection->scan(0, ['match' => 'key*']);

    expect($cursor)->toBe('0')
        ->and($keys)->toBe(['prefix.key1', 'prefix.key2'])
        ->and($link->calls)->toBe([['SCAN', [0, 'MATCH', 'key*']]]);
});
