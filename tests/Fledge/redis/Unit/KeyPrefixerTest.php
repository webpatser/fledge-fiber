<?php

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
