<?php

use Fledge\Fiber\Redis\FledgeRedisClusterConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;

it('builds tcp uri from config', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, [
        'host' => '10.0.0.1',
        'port' => 6380,
    ]);

    expect($uri)->toBe('tcp://10.0.0.1:6380');
});

it('builds uri with defaults', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, []);

    expect($uri)->toBe('tcp://127.0.0.1:6379');
});

it('builds uri with password', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => 'secret',
    ]);

    expect($uri)->toBe('tcp://127.0.0.1:6379?password=secret');
});

it('builds uri with database', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 3,
    ]);

    expect($uri)->toBe('tcp://127.0.0.1:6379?database=3');
});

it('skips database 0 in uri', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
    ]);

    expect($uri)->toBe('tcp://127.0.0.1:6379');
});

it('builds unix socket uri', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, [
        'scheme' => 'unix',
        'path' => '/var/run/redis/redis.sock',
    ]);

    expect($uri)->toBe('unix:///var/run/redis/redis.sock');
});

it('builds uri with password and database and timeout', function () {
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildUri');

    $uri = $method->invoke($connector, [
        'host' => '10.0.0.1',
        'port' => 6380,
        'password' => 'pass',
        'database' => 2,
        'timeout' => 5.0,
    ]);

    expect($uri)->toContain('tcp://10.0.0.1:6380')
        ->and($uri)->toContain('password=pass')
        ->and($uri)->toContain('database=2')
        ->and($uri)->toContain('timeout=5');
});

it('builds a cluster connection without contacting any node', function () {
    $connector = new FledgeRedisConnector;

    $connection = $connector->connectToCluster(
        [
            ['host' => '127.0.0.1', 'port' => 17000],
            ['host' => '127.0.0.1', 'port' => 17001],
        ],
        [],
        ['prefix' => 'app:'],
    );

    expect($connection)->toBeInstanceOf(FledgeRedisClusterConnection::class)
        ->and($connection->getPrefix())->toBe('app:')
        ->and($connection->isCluster())->toBeTrue();
});

it('rejects SELECT to a non-zero database on a cluster connection', function () {
    $connector = new FledgeRedisConnector;

    $connection = $connector->connectToCluster(
        [['host' => '127.0.0.1', 'port' => 17000]],
        [],
        [],
    );

    $connection->select(3);
})->throws(InvalidArgumentException::class, 'Redis Cluster does not support SELECT');

it('allows SELECT 0 on a cluster connection as a no-op', function () {
    $connector = new FledgeRedisConnector;

    $connection = $connector->connectToCluster(
        [['host' => '127.0.0.1', 'port' => 17000]],
        [],
        [],
    );

    expect($connection->select(0))->toBeNull();
});
