<?php

use Fledge\Async\Http\Client\Connection\DefaultConnectionFactory;
use Fledge\Async\Http\Client\Connection\UnlimitedConnectionPool;
use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request as ClientRequest;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\ServerTlsContext;
use Fledge\Fiber\Http\FledgeHandler;
use Psr\Log\NullLogger;

/**
 * Full in-process HTTP/2 loopback: the package's own HTTP server (Http2Driver
 * via ALPN) terminates TLS with a throwaway self-signed certificate, and the
 * async client (and the Guzzle bridge on top of it) talks h2 to it. No
 * external services involved.
 */

/** @return array{string, string} [certPath, keyPath] */
function makeSelfSignedCertificate(): array
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

    $certPath = tempnam(sys_get_temp_dir(), 'fledge-h2-cert-');
    $keyPath = tempnam(sys_get_temp_dir(), 'fledge-h2-key-');
    file_put_contents($certPath, $certPem);
    file_put_contents($keyPath, $keyPem);

    return [$certPath, $keyPath];
}

/** @return array{SocketHttpServer, int} [server, port] */
function startHttp2LoopbackServer(string $certPath, string $keyPath): array
{
    $server = SocketHttpServer::createForDirectAccess(new NullLogger);

    $bindContext = (new BindContext)->withTlsContext(
        (new ServerTlsContext)->withDefaultCertificate(new Certificate($certPath, $keyPath)),
    );

    $server->expose('127.0.0.1:0', $bindContext);
    $server->start(
        new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, [], 'h2 loopback ok')),
        new DefaultErrorHandler,
    );

    $port = $server->getServers()[0]->getAddress()->getPort();

    return [$server, $port];
}

function buildLoopbackClient(): HttpClient
{
    $connectContext = (new ConnectContext)->withTlsContext(
        (new ClientTlsContext('127.0.0.1'))->withoutPeerVerification(),
    );

    return (new HttpClientBuilder)
        ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
        ->build();
}

it('speaks http/2 to its own server over the async client', function () {
    [$certPath, $keyPath] = makeSelfSignedCertificate();
    [$server, $port] = startHttp2LoopbackServer($certPath, $keyPath);

    try {
        $request = new ClientRequest("https://127.0.0.1:{$port}/");
        $request->setProtocolVersions(['2']);

        $response = buildLoopbackClient()->request($request);

        expect($response->getProtocolVersion())->toBe('2')
            ->and($response->getStatus())->toBe(200)
            ->and($response->getBody()->buffer())->toBe('h2 loopback ok');
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('speaks http/2 through the guzzle bridge with the version option', function () {
    [$certPath, $keyPath] = makeSelfSignedCertificate();
    [$server, $port] = startHttp2LoopbackServer($certPath, $keyPath);

    try {
        $guzzle = new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create(new FledgeHandler(buildLoopbackClient())),
        ]);

        $response = $guzzle->get("https://127.0.0.1:{$port}/", ['version' => 2.0]);

        expect($response->getProtocolVersion())->toBe('2')
            ->and($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('h2 loopback ok');
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('stays on http/1.1 through the guzzle bridge by default', function () {
    [$certPath, $keyPath] = makeSelfSignedCertificate();
    [$server, $port] = startHttp2LoopbackServer($certPath, $keyPath);

    try {
        $guzzle = new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create(new FledgeHandler(buildLoopbackClient())),
        ]);

        // No 'version' option: the server offers h2 in ALPN, but the bridge
        // must keep pinning default traffic to HTTP/1.1.
        $response = $guzzle->get("https://127.0.0.1:{$port}/");

        expect($response->getProtocolVersion())->toBe('1.1')
            ->and($response->getStatusCode())->toBe(200);
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});
