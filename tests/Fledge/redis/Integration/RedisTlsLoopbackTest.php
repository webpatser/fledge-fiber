<?php

use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ResourceServerSocket;
use Fledge\Async\Stream\ServerTlsContext;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Stream\listen;

/*
 * In-process TLS loopback speaking just enough canned RESP to answer PING,
 * terminating TLS with a throwaway self-signed certificate. Proves that the
 * ssl context options configured on a Laravel redis connection reach the TLS
 * handshake: cafile establishes trust, verify_peer=false skips verification,
 * and a wrong peer name fails the handshake.
 */

const REDIS_TLS_LOOPBACK_TIMEOUT = 10.0;

/** @return array{string, string} [certPath, keyPath] */
function makeRedisTlsCertificate(): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    assert($key !== false);

    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
    assert($csr !== false);

    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    assert($cert !== false);

    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);

    $certPath = tempnam(sys_get_temp_dir(), 'fledge-redis-tls-cert-');
    $keyPath = tempnam(sys_get_temp_dir(), 'fledge-redis-tls-key-');
    file_put_contents($certPath, $certPem);
    file_put_contents($keyPath, $keyPem);

    return [$certPath, $keyPath];
}

/**
 * Accepts TLS connections and answers every received command with +PONG.
 *
 * @return array{ResourceServerSocket, int} [server, port]
 */
function startRespTlsLoopbackServer(string $certPath, string $keyPath): array
{
    $bindContext = (new BindContext)->withTlsContext(
        (new ServerTlsContext)->withDefaultCertificate(new Certificate($certPath, $keyPath)),
    );

    $server = listen('127.0.0.1:0', $bindContext);
    $port = $server->getAddress()->getPort();

    (void) async(static function () use ($server): void {
        while ($socket = $server->accept()) {
            (void) async(static function () use ($socket): void {
                try {
                    $socket->setupTls(new TimeoutCancellation(REDIS_TLS_LOOPBACK_TIMEOUT));

                    while (($chunk = $socket->read(new TimeoutCancellation(REDIS_TLS_LOOPBACK_TIMEOUT))) !== null) {
                        if (stripos($chunk, 'PING') !== false) {
                            $socket->write("+PONG\r\n");
                        }
                    }
                } catch (\Throwable) {
                    // Handshake failures are expected in the negative test.
                } finally {
                    $socket->close();
                }
            });
        }
    });

    return [$server, $port];
}

function pingOverTls(int $port, array $context): void
{
    $client = createRedisClient(RedisConfig::fromParameters([
        'scheme' => 'tls',
        'host' => '127.0.0.1',
        'port' => $port,
        'timeout' => REDIS_TLS_LOOPBACK_TIMEOUT,
        'context' => $context,
    ]));

    async(static fn () => $client->ping())->await(new TimeoutCancellation(REDIS_TLS_LOOPBACK_TIMEOUT));
}

it('completes the handshake when cafile trusts the server certificate', function () {
    [$certPath, $keyPath] = makeRedisTlsCertificate();
    [$server, $port] = startRespTlsLoopbackServer($certPath, $keyPath);

    try {
        pingOverTls($port, ['cafile' => $certPath]);

        expect(true)->toBeTrue();
    } finally {
        $server->close();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('completes the handshake without verification when verify_peer is false', function () {
    [$certPath, $keyPath] = makeRedisTlsCertificate();
    [$server, $port] = startRespTlsLoopbackServer($certPath, $keyPath);

    try {
        pingOverTls($port, ['verify_peer' => false]);

        expect(true)->toBeTrue();
    } finally {
        $server->close();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('fails the handshake on a peer name mismatch', function () {
    [$certPath, $keyPath] = makeRedisTlsCertificate();
    [$server, $port] = startRespTlsLoopbackServer($certPath, $keyPath);

    try {
        pingOverTls($port, ['cafile' => $certPath, 'peer_name' => 'wrong.example.com']);

        $this->fail('Expected the TLS handshake to fail on the peer name mismatch.');
    } catch (\Fledge\Async\Redis\RedisException $exception) {
        expect($exception->getMessage())->not->toBe('');
    } finally {
        $server->close();
        @unlink($certPath);
        @unlink($keyPath);
    }
});
