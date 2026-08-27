<?php

use Fledge\Async\Database\Postgres\PostgresConfig;

it('emits keepalive options in the connection string', function () {
    $config = new PostgresConfig(
        host: 'localhost',
        user: 'postgres',
        database: 'mydb',
        keepalives: 1,
        keepalivesIdle: 30,
        keepalivesInterval: 10,
        keepalivesCount: 3,
    );

    expect($config->getConnectionString())
        ->toContain('keepalives=1')
        ->toContain('keepalives_idle=30')
        ->toContain('keepalives_interval=10')
        ->toContain('keepalives_count=3');
});

it('omits keepalive options from the connection string when not set', function () {
    $config = new PostgresConfig(host: 'localhost', user: 'postgres', database: 'mydb');

    expect($config->getConnectionString())->not->toContain('keepalives');
});

it('emits keepalives disabled as zero', function () {
    $config = new PostgresConfig(host: 'localhost', keepalives: 0);

    expect($config->getConnectionString())->toContain('keepalives=0');
});

it('parses keepalive options from a connection string', function () {
    $config = PostgresConfig::fromString(
        'host=localhost port=5432 keepalives=1 keepalives_idle=30 keepalives_interval=10 keepalives_count=3'
    );

    expect($config->getKeepalives())->toBe(1)
        ->and($config->getKeepalivesIdle())->toBe(30)
        ->and($config->getKeepalivesInterval())->toBe(10)
        ->and($config->getKeepalivesCount())->toBe(3);
});

it('supports keepalive withers with connection string cache invalidation', function () {
    $config = new PostgresConfig(host: 'localhost');

    expect($config->getConnectionString())->not->toContain('keepalives');

    $config = $config->withKeepalives(1)
        ->withKeepalivesIdle(30)
        ->withKeepalivesInterval(10)
        ->withKeepalivesCount(3);

    expect($config->getConnectionString())
        ->toContain('keepalives=1')
        ->toContain('keepalives_idle=30')
        ->toContain('keepalives_interval=10')
        ->toContain('keepalives_count=3');

    $config = $config->withoutKeepalives()
        ->withoutKeepalivesIdle()
        ->withoutKeepalivesInterval()
        ->withoutKeepalivesCount();

    expect($config->getConnectionString())->not->toContain('keepalives');
});
