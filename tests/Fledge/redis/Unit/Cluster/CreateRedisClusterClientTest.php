<?php

use Fledge\Async\Redis\RedisClient;

use function Fledge\Async\Redis\createRedisClusterClient;

it('rejects an empty seed list', function () {
    createRedisClusterClient([]);
})->throws(InvalidArgumentException::class, 'at least one seed');

it('rejects an invalid seed entry', function () {
    createRedisClusterClient(['127.0.0.1:17000', 42]);
})->throws(InvalidArgumentException::class, 'non-empty string');

it('builds a client from host:port and URI seeds without connecting', function () {
    $client = createRedisClusterClient(['127.0.0.1:1', 'tcp://127.0.0.1:2'], parserFactory: fn () => throw new LogicException('unused'));

    expect($client)->toBeInstanceOf(RedisClient::class);
});
