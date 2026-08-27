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

it('sets only charset without collation', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['charset' => 'latin1']);

    expect($config->getCharset())->toBe('latin1');
});

it('sets only collation', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['collation' => 'utf8mb4_general_ci']);

    expect($config->getCollation())->toBe('utf8mb4_general_ci');
});

it('getSqlMode returns null when no strict or modes key', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'getSqlMode');

    expect($method->invoke($connector, ['host' => '127.0.0.1']))->toBeNull();
});

it('leaves the connect context without tls when no ssl options are configured', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['host' => '10.0.0.1']);

    expect($config->getConnectContext()->getTlsContext())->toBeNull();
});

it('leaves the connect context without tls for non-ssl pdo options', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => '10.0.0.1',
        'options' => [PDO::ATTR_CASE => PDO::CASE_LOWER],
    ]);

    expect($config->getConnectContext()->getTlsContext())->toBeNull();
});

it('maps ssl ca file and peer name into the tls context', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [Pdo\Mysql::ATTR_SSL_CA => '/certs/ca.pem'],
    ]);

    $tls = $config->getConnectContext()->getTlsContext();

    expect($tls)->not->toBeNull()
        ->and($tls->getPeerName())->toBe('db.example.com')
        ->and($tls->getCaFile())->toBe('/certs/ca.pem')
        ->and($tls->hasPeerVerification())->toBeTrue();
});

it('maps ssl ca path into the tls context', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [Pdo\Mysql::ATTR_SSL_CAPATH => '/certs'],
    ]);

    expect($config->getConnectContext()->getTlsContext()->getCaPath())->toBe('/certs');
});

it('maps client certificate and key into the tls context', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [
            Pdo\Mysql::ATTR_SSL_CERT => '/certs/client.crt',
            Pdo\Mysql::ATTR_SSL_KEY => '/certs/client.key',
        ],
    ]);

    $certificate = $config->getConnectContext()->getTlsContext()->getCertificate();

    expect($certificate)->not->toBeNull()
        ->and($certificate->getCertFile())->toBe('/certs/client.crt')
        ->and($certificate->getKeyFile())->toBe('/certs/client.key');
});

it('falls back to the certificate file as key when no ssl key is configured', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [Pdo\Mysql::ATTR_SSL_CERT => '/certs/client.pem'],
    ]);

    $certificate = $config->getConnectContext()->getTlsContext()->getCertificate();

    expect($certificate->getCertFile())->toBe('/certs/client.pem')
        ->and($certificate->getKeyFile())->toBe('/certs/client.pem');
});

it('maps ssl ciphers into the tls context', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [Pdo\Mysql::ATTR_SSL_CIPHER => 'ECDHE-RSA-AES128-GCM-SHA256'],
    ]);

    expect($config->getConnectContext()->getTlsContext()->getCiphers())
        ->toBe('ECDHE-RSA-AES128-GCM-SHA256');
});

it('disables peer verification when verify server cert is false', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false],
    ]);

    $tls = $config->getConnectContext()->getTlsContext();

    expect($tls)->not->toBeNull()
        ->and($tls->hasPeerVerification())->toBeFalse();
});

it('keeps peer verification when verify server cert is true', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [
            Pdo\Mysql::ATTR_SSL_CA => '/certs/ca.pem',
            Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
        ],
    ]);

    expect($config->getConnectContext()->getTlsContext()->hasPeerVerification())->toBeTrue();
});

it('skips tls when connecting over a unix socket', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'unix_socket' => '/var/run/mysqld/mysqld.sock',
        'options' => [Pdo\Mysql::ATTR_SSL_CA => '/certs/ca.pem'],
    ]);

    expect($config->getConnectContext()->getTlsContext())->toBeNull();
});

it('builds config with all fields empty strings', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'username' => '',
        'password' => '',
        'database' => '',
    ]);

    expect($config->getUser())->toBe('')
        ->and($config->getPassword())->toBe('')
        ->and($config->getDatabase())->toBe('');
});
