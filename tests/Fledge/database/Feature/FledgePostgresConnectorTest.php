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

it('builds config with empty optional fields', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'username' => '',
        'password' => '',
    ]);

    expect($config->getUser())->toBe('')
        ->and($config->getPassword())->toBe('');
});

it('applies keepalive options', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'keepalives' => 1,
        'keepalives_idle' => 30,
        'keepalives_interval' => 10,
        'keepalives_count' => 3,
    ]);

    expect($config->getKeepalives())->toBe(1)
        ->and($config->getKeepalivesIdle())->toBe(30)
        ->and($config->getKeepalivesInterval())->toBe(10)
        ->and($config->getKeepalivesCount())->toBe(3);
});

it('omits keepalive options when not configured', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, []);

    expect($config->getKeepalives())->toBeNull()
        ->and($config->getKeepalivesIdle())->toBeNull()
        ->and($config->getKeepalivesInterval())->toBeNull()
        ->and($config->getKeepalivesCount())->toBeNull();
});

it('preserves keepalives disabled as zero', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['keepalives' => 0]);

    expect($config->getKeepalives())->toBe(0);
});

it('applies ssl certificate options', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'sslcert' => '/certs/client.crt',
        'sslkey' => '/certs/client.key',
        'sslrootcert' => '/certs/root.crt',
    ]);

    expect($config->getSslCert())->toBe('/certs/client.crt')
        ->and($config->getSslKey())->toBe('/certs/client.key')
        ->and($config->getSslRootCert())->toBe('/certs/root.crt');
});

it('omits ssl certificate options when not configured', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, []);

    expect($config->getSslCert())->toBeNull()
        ->and($config->getSslKey())->toBeNull()
        ->and($config->getSslRootCert())->toBeNull();
});

it('builds no startup options for a bare config', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildOptions');

    expect($method->invoke($connector, ['host' => '127.0.0.1']))->toBeNull();
});

it('composes session settings as startup packet options', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildOptions');

    $options = $method->invoke($connector, [
        'isolation_level' => 'serializable',
        'timezone' => 'UTC',
        'search_path' => 'public',
        'synchronous_commit' => 'off',
        'charset' => 'utf8',
    ]);

    expect($options)->toBe(
        '-c default_transaction_isolation=serializable'
        .' -c TimeZone=UTC'
        .' -c search_path="public"'
        .' -c synchronous_commit=off'
        .' -c client_encoding=utf8'
    );
});

it('escapes spaces in option values', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildOptions');

    $options = $method->invoke($connector, ['isolation_level' => 'repeatable read']);

    expect($options)->toBe('-c default_transaction_isolation=repeatable\\ read');
});

it('quotes and joins multiple schemas without spaces', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildOptions');

    $options = $method->invoke($connector, ['search_path' => 'public,audit']);

    expect($options)->toBe('-c search_path="public","audit"');
});

it('accepts schema as a search_path alias', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildOptions');

    expect($method->invoke($connector, ['schema' => 'tenant']))->toBe('-c search_path="tenant"');
});

it('emits startup options in the connection string', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => '127.0.0.1',
        'isolation_level' => 'repeatable read',
        'search_path' => 'public,audit',
    ]);

    expect($config->getConnectionString())->toContain(
        "options='-c default_transaction_isolation=repeatable\\\\ read"
        .' -c search_path=\\"public\\",\\"audit\\"\''
    );
});

it('preserves host when using connect_via', function () {
    $connector = new FledgePostgresConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'primary.example.com',
        'connect_via_port' => 6432,
    ]);

    // Host should stay as the original
    expect($config->getHost())->toBe('primary.example.com');
});
