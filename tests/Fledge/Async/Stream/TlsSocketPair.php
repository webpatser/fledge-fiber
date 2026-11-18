<?php declare(strict_types=1);

namespace Fledge\Async\Test\Fledge\Async\Stream;

/**
 * A connected pair of real TLS sockets over loopback TCP, with a throwaway self-signed certificate.
 */
final class TlsSocketPair
{
    /**
     * @return array{resource, resource} [client, server], both non-blocking with TLS enabled.
     */
    public static function create(): array
    {
        $key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        $certificate = \openssl_csr_sign(\openssl_csr_new(['commonName' => 'localhost'], $key), null, $key, 1);
        \openssl_x509_export($certificate, $certificatePem);
        \openssl_pkey_export($key, $keyPem);

        $file = \tempnam(\sys_get_temp_dir(), 'fledge-tls-');
        \file_put_contents($file, $certificatePem . $keyPem);

        try {
            $listener = \stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $errstr,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                \stream_context_create(['ssl' => ['local_cert' => $file, 'verify_peer' => false]]),
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
                \stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
            );
            $server = \stream_socket_accept($listener, 5);
            \fclose($listener);

            \stream_set_blocking($client, false);
            \stream_set_blocking($server, false);

            $clientDone = $serverDone = 0;

            for ($i = 0; $i < 2000 && ($clientDone !== true || $serverDone !== true); $i++) {
                if ($serverDone !== true) {
                    $serverDone = \stream_socket_enable_crypto($server, true, \STREAM_CRYPTO_METHOD_TLS_SERVER);
                }

                if ($clientDone !== true) {
                    $clientDone = \stream_socket_enable_crypto($client, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
                }

                if ($serverDone === false || $clientDone === false) {
                    throw new \RuntimeException('TLS handshake failed');
                }

                \usleep(500);
            }

            if ($clientDone !== true || $serverDone !== true) {
                throw new \RuntimeException('TLS handshake did not complete');
            }
        } finally {
            \unlink($file);
        }

        return [$client, $server];
    }
}
