<?php

use Fledge\Async\Redis\RedisException;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;
use Fledge\Fiber\Redis\UnsupportedRedisOptionException;

it('rejects a non-none serializer', function (string|int $serializer) {
    (new FledgeRedisConnector)->connect(['host' => '127.0.0.1', 'serializer' => $serializer], []);
})->with([
    'php' => ['php'],
    'igbinary' => ['igbinary'],
    'json' => ['json'],
    'Redis::SERIALIZER_PHP constant' => [1],
])->throws(UnsupportedRedisOptionException::class, 'serializer');

it('tolerates serializer none', function (string|int $serializer) {
    $connection = (new FledgeRedisConnector)->connect(['host' => '127.0.0.1', 'serializer' => $serializer], []);

    expect($connection)->toBeInstanceOf(FledgeRedisConnection::class);
})->with(['none', 0]);

it('rejects a non-none compression', function (string|int $compression) {
    (new FledgeRedisConnector)->connect(['host' => '127.0.0.1', 'compression' => $compression], []);
})->with([
    'lzf' => ['lzf'],
    'zstd' => ['zstd'],
    'lz4' => ['lz4'],
    'Redis::COMPRESSION_LZF constant' => [1],
])->throws(UnsupportedRedisOptionException::class, 'compression');

it('tolerates compression none', function (string|int $compression) {
    $connection = (new FledgeRedisConnector)->connect(['host' => '127.0.0.1', 'compression' => $compression], []);

    expect($connection)->toBeInstanceOf(FledgeRedisConnection::class);
})->with(['none', 0]);

it('rejects pack_ignore_numbers', function () {
    (new FledgeRedisConnector)->connect(['host' => '127.0.0.1', 'pack_ignore_numbers' => true], []);
})->throws(UnsupportedRedisOptionException::class, 'pack_ignore_numbers');

it('rejects sentinel replication', function () {
    (new FledgeRedisConnector)->connect(['host' => '127.0.0.1', 'replication' => 'sentinel'], []);
})->throws(UnsupportedRedisOptionException::class, 'use a direct connection or predis');

it('rejects distribute failover on cluster connections', function () {
    (new FledgeRedisConnector)->connectToCluster(
        [['host' => '127.0.0.1', 'port' => 17000]],
        ['failover' => 'distribute'],
        [],
    );
})->throws(UnsupportedRedisOptionException::class, 'Replica read routing');

it('rejects a non-redis cluster driver on cluster connections', function () {
    (new FledgeRedisConnector)->connectToCluster(
        [['host' => '127.0.0.1', 'port' => 17000]],
        [],
        ['cluster' => 'predis'],
    );
})->throws(UnsupportedRedisOptionException::class, 'client-side sharding');

it('ignores the cluster driver option on single connections', function () {
    // Laravel's global options array carries `cluster` for every connection;
    // upstream connectors ignore it outside cluster resolution.
    $connection = (new FledgeRedisConnector)->connect(['host' => '127.0.0.1'], ['cluster' => 'predis']);

    expect($connection)->toBeInstanceOf(FledgeRedisConnection::class);
});

it('silently tolerates options without semantic impact', function () {
    $connection = (new FledgeRedisConnector)->connect([
        'host' => '127.0.0.1',
        'scan' => 1,
        'persistent' => true,
        'persistent_id' => 'worker',
        'compression_level' => 6,
    ], []);

    expect($connection)->toBeInstanceOf(FledgeRedisConnection::class);
});

it('exposes unsupported option failures as RedisException', function () {
    expect(new UnsupportedRedisOptionException('nope'))->toBeInstanceOf(RedisException::class);
});
