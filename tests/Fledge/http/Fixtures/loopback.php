<?php

/**
 * Shared loopback fixtures for the Guzzle bridge parity suite.
 *
 * Loaded via require_once from the test files that need them; every symbol
 * is guarded so parallel workers and the single-process runner can both
 * load this file alongside any test file.
 *
 * Every request against these servers MUST be time-bounded (the Guzzle
 * timeout/connect_timeout options or a TimeoutCancellation). The loopback
 * answers in milliseconds, so a bound firing means a handshake or ALPN
 * negotiation stalled. Without bounds a stalled negotiation waits forever
 * and holds the CI runner until the job timeout with no output naming the
 * test, which is exactly how an earlier hang in this suite went
 * undiagnosed.
 */

use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ServerTlsContext;
use Fledge\Fiber\Http\FledgeHandler;
use Psr\Log\NullLogger;

use function Fledge\Async\Stream\listen;

if (! defined('LOOPBACK_TIMEOUT')) {
    define('LOOPBACK_TIMEOUT', 10.0);
}

if (! function_exists('makeSelfSignedCertificate')) {
    /**
     * Create a throwaway self-signed certificate for 127.0.0.1, including a
     * subjectAltName so peer verification against the exported certificate
     * can succeed.
     *
     * @return array{string, string} [certPath, keyPath]
     */
    function makeSelfSignedCertificate(): array
    {
        $configPath = tempnam(sys_get_temp_dir(), 'fledge-openssl-cnf-');
        file_put_contents($configPath, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[v3]',
            'subjectAltName = IP:127.0.0.1',
        ]));

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'config' => $configPath,
        ]);
        assert($key !== false);

        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, [
            'digest_alg' => 'sha256',
            'config' => $configPath,
        ]);
        assert($csr !== false);

        $cert = openssl_csr_sign($csr, null, $key, 1, [
            'digest_alg' => 'sha256',
            'config' => $configPath,
            'x509_extensions' => 'v3',
        ]);
        assert($cert !== false);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, ['config' => $configPath]);

        @unlink($configPath);

        $certPath = tempnam(sys_get_temp_dir(), 'fledge-loopback-cert-');
        $keyPath = tempnam(sys_get_temp_dir(), 'fledge-loopback-key-');
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);

        return [$certPath, $keyPath];
    }
}

if (! function_exists('startLoopbackServer')) {
    /**
     * Start an in-process HTTP server on an ephemeral 127.0.0.1 port.
     *
     * @param array{string, string}|null $tls [certPath, keyPath] to terminate TLS, null for plain HTTP
     * @return array{SocketHttpServer, int} [server, port]
     */
    function startLoopbackServer(?array $tls, Closure $handler): array
    {
        $server = SocketHttpServer::createForDirectAccess(new NullLogger);

        $bindContext = new BindContext;

        if ($tls !== null) {
            $bindContext = $bindContext->withTlsContext(
                (new ServerTlsContext)->withDefaultCertificate(new Certificate($tls[0], $tls[1])),
            );
        }

        $server->expose('127.0.0.1:0', $bindContext);
        $server->start(new ClosureRequestHandler($handler), new DefaultErrorHandler);

        $port = $server->getServers()[0]->getAddress()->getPort();

        return [$server, $port];
    }
}

if (! function_exists('makeGuzzleClient')) {
    /**
     * Build a Guzzle client on the Fledge handler with the production
     * middleware stack (HandlerStack::create wires RedirectMiddleware,
     * cookies, prepare-body, and http_errors exactly like real traffic).
     */
    function makeGuzzleClient(array $config = [], ?FledgeHandler $handler = null): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client(array_merge([
            'handler' => \GuzzleHttp\HandlerStack::create($handler ?? new FledgeHandler),
            'connect_timeout' => LOOPBACK_TIMEOUT,
            'timeout' => LOOPBACK_TIMEOUT,
        ], $config));
    }
}

if (! function_exists('refusedPort')) {
    /**
     * An ephemeral port that nothing listens on anymore, so connecting to
     * it is refused immediately.
     */
    function refusedPort(): int
    {
        $server = listen('127.0.0.1:0');
        $port = $server->getAddress()->getPort();
        $server->close();

        return $port;
    }
}
