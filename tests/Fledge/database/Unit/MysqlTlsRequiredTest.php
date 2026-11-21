<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Loop;

/**
 * Connect to a throwaway local server whose handshake greeting does not advertise CLIENT_SSL,
 * as a network attacker stripping the bit would produce. Returns the connection failure and
 * every byte the client sent after the greeting.
 *
 * @return array{0: SqlConnectionException, 1: string}
 */
function connectToServerWithoutClientSsl(?ConnectContext $context): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    // Protocol 10 greeting: version, connection id, 8 bytes auth data, filler, lower capability
    // flags with everything set except CLIENT_SSL (0x0800).
    $payload = "\x0a"."8.0.0-fake\0".pack('V', 1).'abcdefgh'."\0".pack('v', 0xFFFF & ~0x0800);
    $greeting = substr(pack('V', strlen($payload)), 0, 3)."\x00".$payload;

    $received = '';
    $accepted = null;

    $watcher = Loop::onReadable($server, static function (string $id, $server) use ($greeting, &$received, &$accepted): void {
        Loop::cancel($id);
        $accepted = stream_socket_accept($server, 0);
        fwrite($accepted, $greeting);

        Loop::onReadable($accepted, static function (string $id, $client) use (&$received): void {
            Loop::cancel($id);
            $received .= (string) fread($client, 65536);
            fclose($client);
        });
    });

    try {
        (new SocketMysqlConnector)->connect(new MysqlConfig('127.0.0.1', $port, 'app', 's3cret-password', null, $context));
    } catch (SqlConnectionException $e) {
        return [$e, $received];
    } finally {
        Loop::cancel($watcher);
        fclose($server);
    }

    throw new RuntimeException('Expected the connection to fail');
}

it('refuses to continue in plaintext when TLS is configured but the greeting lacks CLIENT_SSL', function () {
    $context = (new ConnectContext)->withTlsContext((new ClientTlsContext)->withPeerName('127.0.0.1'));

    [$e, $received] = connectToServerWithoutClientSsl($context);

    expect($e->getMessage())->toContain('CLIENT_SSL')
        ->and($received)->not->toContain('app')
        ->and($received)->not->toContain('s3cret-password')
        ->and($received)->toBe('');
});

it('still sends the auth packet in plaintext when no TLS context is configured', function () {
    [, $received] = connectToServerWithoutClientSsl(null);

    expect($received)->toContain("app\0");
});

/**
 * Run the real connector's config building for the given PDO options and return the
 * resulting TLS context. buildConfig() is protected, so a minimal subclass exposes it.
 */
function tlsContextForOptions(array $options): ?ClientTlsContext
{
    $connector = new class extends FledgeMySqlConnector
    {
        public function config(array $config): MysqlConfig
        {
            return $this->buildConfig($config);
        }
    };

    return $connector->config(['host' => 'db.example.test', 'options' => $options])
        ->getConnectContext()
        ->getTlsContext();
}

it('does not request TLS for options that connect in plaintext on stock pdo_mysql', function (array $options) {
    expect(tlsContextForOptions($options))->toBeNull();
})->with([
    'only verify flag off' => [[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]],
    'only verify flag on' => [[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true]],
    'only verify flag null' => [[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => null]],
    'no options' => [[]],
]);

it('requests TLS when an SSL key is present, whatever its value, like stock pdo_mysql', function (array $options) {
    // Stock pdo_mysql requests TLS for each of these keys on its own, even when the
    // value is null, false, '' or 0, and then refuses a server without TLS. Treating
    // such a key as absent would silently downgrade to plaintext.
    expect(tlsContextForOptions($options))->toBeInstanceOf(ClientTlsContext::class);
})->with([
    'null CA' => [[\Pdo\Mysql::ATTR_SSL_CA => null]],
    'empty CA' => [[\Pdo\Mysql::ATTR_SSL_CA => '']],
    'false CA' => [[\Pdo\Mysql::ATTR_SSL_CA => false]],
    'zero CA' => [[\Pdo\Mysql::ATTR_SSL_CA => 0]],
    'null CA with verify flag off' => [[\Pdo\Mysql::ATTR_SSL_CA => null, \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]],
    'empty CA path' => [[\Pdo\Mysql::ATTR_SSL_CAPATH => '']],
    'null CA path' => [[\Pdo\Mysql::ATTR_SSL_CAPATH => null]],
    'empty cert' => [[\Pdo\Mysql::ATTR_SSL_CERT => '']],
    'null cert' => [[\Pdo\Mysql::ATTR_SSL_CERT => null]],
    'empty key' => [[\Pdo\Mysql::ATTR_SSL_KEY => '']],
    'null key' => [[\Pdo\Mysql::ATTR_SSL_KEY => null]],
    'empty cipher' => [[\Pdo\Mysql::ATTR_SSL_CIPHER => '']],
    'null cipher' => [[\Pdo\Mysql::ATTR_SSL_CIPHER => null]],
]);

it('requests TLS but leaves the setting at its default for an empty SSL option value', function (int $option, string $getter, ?string $default, mixed $value) {
    // An empty value must not reach the TLS context as an empty cafile, capath,
    // certificate, key file or cipher list; TLS is still requested on key presence.
    $tls = tlsContextForOptions([$option => $value]);

    expect($tls)->toBeInstanceOf(ClientTlsContext::class)
        ->and($tls->{$getter}())->toBe($default);
})->with([
    'CA' => [\Pdo\Mysql::ATTR_SSL_CA, 'getCaFile', null],
    'CA path' => [\Pdo\Mysql::ATTR_SSL_CAPATH, 'getCaPath', null],
    'cert' => [\Pdo\Mysql::ATTR_SSL_CERT, 'getCertificate', null],
    'key' => [\Pdo\Mysql::ATTR_SSL_KEY, 'getCertificate', null],
    'cipher' => [\Pdo\Mysql::ATTR_SSL_CIPHER, 'getCiphers', \OPENSSL_DEFAULT_STREAM_CIPHERS],
])->with([
    'empty string' => [''],
    'null' => [null],
    'false' => [false],
    'zero' => [0],
]);

it('uses the certificate file as key file when the SSL key is empty', function (mixed $key) {
    $certificate = tlsContextForOptions([
        \Pdo\Mysql::ATTR_SSL_CERT => '/path/client.pem',
        \Pdo\Mysql::ATTR_SSL_KEY => $key,
    ])->getCertificate();

    expect($certificate->getCertFile())->toBe('/path/client.pem')
        ->and($certificate->getKeyFile())->toBe('/path/client.pem');
})->with([
    'empty string' => [''],
    'null' => [null],
    'false' => [false],
    'zero' => [0],
]);

it('passes non-empty SSL option values through to the TLS context', function () {
    $tls = tlsContextForOptions([
        \Pdo\Mysql::ATTR_SSL_CA => '/path/ca.pem',
        \Pdo\Mysql::ATTR_SSL_CAPATH => '/path/certs',
        \Pdo\Mysql::ATTR_SSL_CERT => '/path/client.pem',
        \Pdo\Mysql::ATTR_SSL_KEY => '/path/client.key',
        \Pdo\Mysql::ATTR_SSL_CIPHER => 'ECDHE-RSA-AES256-GCM-SHA384',
    ]);

    expect($tls->getCaFile())->toBe('/path/ca.pem')
        ->and($tls->getCaPath())->toBe('/path/certs')
        ->and($tls->getCertificate()->getCertFile())->toBe('/path/client.pem')
        ->and($tls->getCertificate()->getKeyFile())->toBe('/path/client.key')
        ->and($tls->getCiphers())->toBe('ECDHE-RSA-AES256-GCM-SHA384');
});

it('verifies an empty SSL CA against the system trust store like no CA at all', function (mixed $ca) {
    // No cafile or capath in the stream context means OpenSSL's default trust store,
    // with peer and peer-name verification still on.
    $options = tlsContextForOptions([\Pdo\Mysql::ATTR_SSL_CA => $ca])->toStreamContextArray()['ssl'];

    expect($options)->toHaveKey('verify_peer')
        ->and($options)->not->toHaveKey('cafile')
        ->and($options)->not->toHaveKey('capath')
        ->and($options['verify_peer'])->toBeTrue()
        ->and($options['verify_peer_name'])->toBeTrue()
        ->and($options['peer_name'])->toBe('db.example.test');
})->with([
    'empty string' => [''],
    'null' => [null],
    'false' => [false],
    'zero' => [0],
]);

it('keeps peer verification for a present but empty SSL key unless the verify flag is off', function () {
    expect(tlsContextForOptions([\Pdo\Mysql::ATTR_SSL_CA => null])->hasPeerVerification())->toBeTrue()
        ->and(tlsContextForOptions([
            \Pdo\Mysql::ATTR_SSL_CA => null,
            \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
        ])->hasPeerVerification())->toBeFalse();
});

it('requests TLS with peer verification when a CA file is configured', function () {
    $tls = tlsContextForOptions([\Pdo\Mysql::ATTR_SSL_CA => '/path/ca.pem']);

    expect($tls)->toBeInstanceOf(ClientTlsContext::class)
        ->and($tls->getCaFile())->toBe('/path/ca.pem')
        ->and($tls->getPeerName())->toBe('db.example.test')
        ->and($tls->hasPeerVerification())->toBeTrue();
});

it('requests TLS without peer verification when a CA file and verify flag off are configured', function () {
    $tls = tlsContextForOptions([
        \Pdo\Mysql::ATTR_SSL_CA => '/path/ca.pem',
        \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);

    expect($tls)->toBeInstanceOf(ClientTlsContext::class)
        ->and($tls->getCaFile())->toBe('/path/ca.pem')
        ->and($tls->hasPeerVerification())->toBeFalse();
});
