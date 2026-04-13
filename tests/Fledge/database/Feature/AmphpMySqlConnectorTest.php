<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;

it('builds config with host and port', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => '10.0.0.1',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'mydb',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    expect($config)->toBeInstanceOf(MysqlConfig::class)
        ->and($config->getHost())->toBe('10.0.0.1')
        ->and($config->getPort())->toBe(3307)
        ->and($config->getUser())->toBe('root')
        ->and($config->getPassword())->toBe('secret')
        ->and($config->getDatabase())->toBe('mydb')
        ->and($config->getCharset())->toBe('utf8mb4')
        ->and($config->getCollation())->toBe('utf8mb4_unicode_ci');
});

it('uses sensible defaults', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, []);

    expect($config->getHost())->toBe('127.0.0.1')
        ->and($config->getPort())->toBe(3306)
        ->and($config->getCharset())->toBe(MysqlConfig::DEFAULT_CHARSET)
        ->and($config->getCollation())->toBe(MysqlConfig::DEFAULT_COLLATE);
});

it('uses unix socket as host', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'unix_socket' => '/var/run/mysqld/mysqld.sock',
    ]);

    expect($config->getHost())->toBe('/var/run/mysqld/mysqld.sock');
});

it('sets strict sql mode', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['strict' => true]);

    expect($config->getSqlMode())->toBe(
        'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
    );
});

it('sets non-strict sql mode', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['strict' => false]);

    expect($config->getSqlMode())->toBe('NO_ENGINE_SUBSTITUTION');
});

it('sets custom sql modes', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'modes' => ['STRICT_TRANS_TABLES', 'NO_ZERO_DATE'],
    ]);

    expect($config->getSqlMode())->toBe('STRICT_TRANS_TABLES,NO_ZERO_DATE');
});

it('returns null sql mode without strict config', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, []);

    expect($config->getSqlMode())->toBeNull();
});
