<?php

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

it('throws on cluster connections', function () {
    $connector = new FledgeRedisConnector;
    $connector->connectToCluster([], [], []);
})->throws(InvalidArgumentException::class, 'does not support Redis Cluster');
