<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\SqlException;
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
        ->and($config->getCharset())->toBe('')
        ->and($config->getCollation())->toBe('');
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

    expect($config->getCharset())->toBe('latin1')
        ->and($config->getCollation())->toBe('');
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

it('getSqlMode includes NO_AUTO_CREATE_USER for strict mode on MySQL before 8.0.11', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'getSqlMode');

    expect($method->invoke($connector, ['strict' => true, 'version' => '5.7.44']))->toBe(
        'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'
    );
});

it('getSqlMode omits NO_AUTO_CREATE_USER for strict mode on MySQL 8.0.11 and later', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'getSqlMode');

    $modern = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    expect($method->invoke($connector, ['strict' => true, 'version' => '8.0.11']))->toBe($modern)
        ->and($method->invoke($connector, ['strict' => true, 'version' => '8.4.0']))->toBe($modern)
        ->and($method->invoke($connector, ['strict' => true]))->toBe($modern);
});

it('getSqlMode ignores version when strict is false', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'getSqlMode');

    expect($method->invoke($connector, ['strict' => false, 'version' => '5.7.44']))->toBe('NO_ENGINE_SUBSTITUTION');
});

it('composes session statements in order', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'sessionStatements');

    $statements = $method->invoke($connector, [
        'isolation_level' => 'READ COMMITTED',
        'timezone' => '+01:00',
        'options' => [Pdo\Mysql::ATTR_INIT_COMMAND => "SET @app='fledge'"],
    ]);

    expect($statements)->toBe([
        'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        "SET time_zone='+01:00'",
        "SET @app='fledge'",
    ]);
});

it('composes no session statements for a bare config', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'sessionStatements');

    expect($method->invoke($connector, ['host' => '127.0.0.1']))->toBe([]);
});

it('composes only the configured session statements', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'sessionStatements');

    expect($method->invoke($connector, ['timezone' => 'UTC']))->toBe(["SET time_zone='UTC'"]);
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

it('does not request tls when verify server cert is false on its own', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false],
    ]);

    expect($config->getConnectContext()->getTlsContext())->toBeNull();
});

it('disables peer verification when verify server cert is false next to an ssl ca', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db.example.com',
        'options' => [
            Pdo\Mysql::ATTR_SSL_CA => '/certs/ca.pem',
            Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
        ],
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

it('leaves local infile and the server public key at their defaults without options', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['host' => 'db', 'options' => []]);

    expect($config->isLocalInfileEnabled())->toBeFalse()
        ->and($config->getLocalInfileDirectory())->toBeNull()
        ->and($config->getKey())->toBe('');
});

it('maps PDO local infile options like pdo_mysql', function (int|string|bool $value, bool $enabled) {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, ['host' => 'db', 'options' => [Pdo\Mysql::ATTR_LOCAL_INFILE => $value]]);

    expect($config->isLocalInfileEnabled())->toBe($enabled);
})->with([
    'true' => [true, true],
    'one' => [1, true],
    'false' => [false, false],
    'zero' => [0, false],
]);

it('maps the PDO local infile directory', function () {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $config = $method->invoke($connector, [
        'host' => 'db',
        'options' => [Pdo\Mysql::ATTR_LOCAL_INFILE_DIRECTORY => '/srv/imports'],
    ]);

    expect($config->getLocalInfileDirectory())->toBe('/srv/imports')
        ->and($config->isLocalInfileEnabled())->toBeFalse();
});

it('pins the server public key read from the PDO server public key path', function () {
    $pem = openssl_pkey_get_details(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]))['key'];
    $path = tempnam(sys_get_temp_dir(), 'fledge-mysql-key-');
    file_put_contents($path, $pem);

    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    try {
        $config = $method->invoke($connector, [
            'host' => 'db',
            'options' => [Pdo\Mysql::ATTR_SERVER_PUBLIC_KEY => $path],
        ]);
    } finally {
        unlink($path);
    }

    expect($config->getKey())->toBe($pem);
});

it('fails instead of fetching the key from the server when the public key file cannot be used', function (string $contents) {
    $connector = new FledgeMySqlConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    $path = sys_get_temp_dir().'/fledge-mysql-key-'.bin2hex(random_bytes(4));
    if ($contents !== '') {
        file_put_contents($path, $contents);
    }

    try {
        expect(fn () => $method->invoke($connector, [
            'host' => 'db',
            'options' => [Pdo\Mysql::ATTR_SERVER_PUBLIC_KEY => $path],
        ]))->toThrow(SqlException::class, $path);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
})->with([
    'missing file' => '',
    'not a public key' => 'not a pem key',
]);

it('fails the Laravel connect when the public key file is missing', function () {
    expect(fn () => (new FledgeMySqlConnector)->connect([
        'host' => '127.0.0.1',
        'options' => [Pdo\Mysql::ATTR_SERVER_PUBLIC_KEY => '/nonexistent/fledge/server-key.pem'],
    ]))->toThrow(PDOException::class, '/nonexistent/fledge/server-key.pem');
});
