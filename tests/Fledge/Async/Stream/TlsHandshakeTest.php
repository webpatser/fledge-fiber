<?php

declare(strict_types=1);

use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;
use function Fledge\Async\Stream\Internal\setupTls;

/*
 * setupTls() must wait in the direction OpenSSL is blocked on. The server below sends a certificate chain of more
 * than 100 KB through 4 KB socket buffers while the client has not read anything yet, so its handshake step returns
 * 0 with STREAM_CRYPTO_STATUS_WANT_WRITE. Waiting for readable at that point deadlocks: the client cannot answer
 * before it has the whole chain, and the server never resumes to write the rest.
 */

/**
 * @return array{resource, resource, string} [client, server, certificate file]
 */
function tlsPairWithLargeChain(): array
{
    $key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    \openssl_pkey_export($key, $keyPem);

    $pem = '';
    for ($i = 0; $i < 100; $i++) {
        $csr = \openssl_csr_new(['commonName' => $i === 0 ? 'localhost' : "chain-$i"], $key);
        \openssl_x509_export(\openssl_csr_sign($csr, null, $key, 1, serial: $i + 1), $certificatePem);
        $pem .= $certificatePem;
    }

    $file = \tempnam(\sys_get_temp_dir(), 'fledge-tls-chain-');
    \file_put_contents($file, $pem . $keyPem);

    // Own contexts per side: setupTls() writes its options into the stream's context, and streams opened without one
    // share the process-wide default context.
    $listener = \stream_socket_server(
        'tcp://127.0.0.1:0',
        $errno,
        $errstr,
        \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        \stream_context_create(),
    );
    if ($listener === false) {
        throw new \RuntimeException("Could not listen: $errstr");
    }

    $client = \stream_socket_client(
        'tcp://' . \stream_socket_get_name($listener, false),
        $errno,
        $errstr,
        5,
        \STREAM_CLIENT_CONNECT,
        \stream_context_create(),
    );
    $server = \stream_socket_accept($listener, 5);
    \fclose($listener);

    \socket_set_option(\socket_import_stream($server), \SOL_SOCKET, \SO_SNDBUF, 4096);
    \socket_set_option(\socket_import_stream($client), \SOL_SOCKET, \SO_RCVBUF, 4096);

    \stream_set_blocking($client, false);
    \stream_set_blocking($server, false);

    return [$client, $server, $file];
}

it('completes a handshake that blocks on writing a large certificate chain', function () {
    [$client, $server, $file] = tlsPairWithLargeChain();

    $serverOptions = ['ssl' => ['local_cert' => $file, 'crypto_method' => \STREAM_CRYPTO_METHOD_TLS_SERVER]];
    $clientOptions = ['ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'crypto_method' => \STREAM_CRYPTO_METHOD_TLS_CLIENT,
    ]];

    try {
        // Precondition: the server really is blocked on writing, so setupTls() enters its wait loop on WANT_WRITE.
        \stream_context_set_options($client, $clientOptions);
        \stream_context_set_options($server, $serverOptions);
        expect(\stream_socket_enable_crypto($client, true))->toBe(0)
            ->and(\stream_socket_get_crypto_status($client))->toBe(\STREAM_CRYPTO_STATUS_WANT_READ)
            ->and(\stream_socket_enable_crypto($server, true))->toBe(0)
            ->and(\stream_socket_get_crypto_status($server))->toBe(\STREAM_CRYPTO_STATUS_WANT_WRITE);

        $start = \hrtime(true);

        await([
            async(fn () => setupTls($server, $serverOptions, new TimeoutCancellation(5))),
            async(fn () => setupTls($client, $clientOptions, new TimeoutCancellation(5))),
        ]);

        expect((\hrtime(true) - $start) / 1e9)->toBeLessThan(5)
            ->and(\stream_get_meta_data($server)['crypto'] ?? null)->toBeArray()
            ->and(\stream_get_meta_data($client)['crypto'] ?? null)->toBeArray()
            ->and(\stream_socket_get_crypto_status($server))->toBe(\STREAM_CRYPTO_STATUS_NONE);

        \fwrite($server, 'ping');
        $read = '';
        for ($i = 0; $i < 200 && $read !== 'ping'; $i++) {
            $read .= (string) \fread($client, 4);
            \usleep(1000);
        }

        expect($read)->toBe('ping');
    } finally {
        \fclose($client);
        \fclose($server);
        \unlink($file);
    }
});
