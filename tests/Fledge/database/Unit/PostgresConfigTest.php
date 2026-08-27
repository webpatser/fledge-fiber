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

it('emits ssl certificate options in the connection string', function () {
    $config = new PostgresConfig(
        host: 'localhost',
        user: 'postgres',
        database: 'mydb',
        sslCert: '/certs/client.crt',
        sslKey: '/certs/client.key',
        sslRootCert: '/certs/root.crt',
    );

    expect($config->getConnectionString())
        ->toContain("sslcert='/certs/client.crt'")
        ->toContain("sslkey='/certs/client.key'")
        ->toContain("sslrootcert='/certs/root.crt'");
});

it('quotes ssl certificate paths containing spaces', function () {
    $config = new PostgresConfig(
        host: 'localhost',
        sslCert: '/my certs/client.crt',
    );

    expect($config->getConnectionString())->toContain("sslcert='/my certs/client.crt'");
});

it('omits ssl certificate options from the connection string when not set', function () {
    $config = new PostgresConfig(host: 'localhost', user: 'postgres', database: 'mydb');

    expect($config->getConnectionString())
        ->not->toContain('sslcert')
        ->not->toContain('sslkey')
        ->not->toContain('sslrootcert');
});

it('parses ssl certificate options from a connection string', function () {
    $config = PostgresConfig::fromString(
        'host=localhost port=5432 sslcert=/certs/client.crt sslkey=/certs/client.key sslrootcert=/certs/root.crt'
    );

    expect($config->getSslCert())->toBe('/certs/client.crt')
        ->and($config->getSslKey())->toBe('/certs/client.key')
        ->and($config->getSslRootCert())->toBe('/certs/root.crt');
});

it('supports ssl certificate withers with connection string cache invalidation', function () {
    $config = new PostgresConfig(host: 'localhost');

    expect($config->getConnectionString())->not->toContain('sslcert');

    $config = $config->withSslCert('/certs/client.crt')
        ->withSslKey('/certs/client.key')
        ->withSslRootCert('/certs/root.crt');

    expect($config->getConnectionString())
        ->toContain("sslcert='/certs/client.crt'")
        ->toContain("sslkey='/certs/client.key'")
        ->toContain("sslrootcert='/certs/root.crt'");

    $config = $config->withoutSslCert()
        ->withoutSslKey()
        ->withoutSslRootCert();

    expect($config->getConnectionString())
        ->not->toContain('sslcert')
        ->not->toContain('sslkey')
        ->not->toContain('sslrootcert');
});
