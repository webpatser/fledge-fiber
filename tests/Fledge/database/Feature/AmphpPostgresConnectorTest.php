<?php

use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;

it('builds config with host and port', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => '10.0.0.1',
        'port' => 5433,
        'username' => 'postgres',
        'password' => 'secret',
        'database' => 'mydb',
    ]);

    expect($config)->toBeInstanceOf(PostgresConfig::class)
        ->and($config->getHost())->toBe('10.0.0.1')
        ->and($config->getPort())->toBe(5433)
        ->and($config->getUser())->toBe('postgres')
        ->and($config->getPassword())->toBe('secret')
        ->and($config->getDatabase())->toBe('mydb');
});

it('uses sensible defaults', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, []);

    expect($config->getHost())->toBe('127.0.0.1')
        ->and($config->getPort())->toBe(5432);
});

it('applies ssl mode', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['sslmode' => 'require']);

    expect($config->getSslMode())->toBe('require');
});

it('applies application name', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['application_name' => 'my_app']);

    expect($config->getApplicationName())->toBe('my_app');
});

it('uses connect_via for pgbouncer', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'primary.example.com',
        'port' => 5432,
        'database' => 'app_db',
        'connect_via_database' => 'pgbouncer_db',
        'connect_via_port' => 6432,
    ]);

    expect($config->getDatabase())->toBe('pgbouncer_db')
        ->and($config->getPort())->toBe(6432);
});
