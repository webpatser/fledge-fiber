<?php

use Fledge\Async\Http\Server\Request as ServerRequest;
use Fledge\Async\Http\Server\Response as ServerResponse;
use GuzzleHttp\Exception\ConnectException;

require_once __DIR__.'/../Fixtures/loopback.php';

const TLS_BODY = 'over tls';
const GZIP_BODY = 'please compress this payload for the raw decode test';

/** @return array{\Fledge\Async\Http\Server\SocketHttpServer, int, string, string} */
function startTlsLoopback(): array
{
    [$certPath, $keyPath] = makeSelfSignedCertificate();

    [$server, $port] = startLoopbackServer([$certPath, $keyPath], function (ServerRequest $request): ServerResponse {
        if ($request->getUri()->getPath() === '/gzip') {
            return new ServerResponse(200, ['content-encoding' => 'gzip'], gzencode(GZIP_BODY));
        }

        return new ServerResponse(200, [], TLS_BODY);
    });

    return [$server, $port, $certPath, $keyPath];
}

it('fails peer verification against a self-signed certificate by default', function () {
    [$server, $port, $certPath, $keyPath] = startTlsLoopback();

    try {
        makeGuzzleClient()->get("https://127.0.0.1:{$port}/");

        $this->fail('Expected a ConnectException for the untrusted certificate');
    } catch (ConnectException $e) {
        expect($e->getPrevious())->toBeInstanceOf(\Fledge\Async\Http\Client\SocketException::class);
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('connects with verify disabled', function () {
    [$server, $port, $certPath, $keyPath] = startTlsLoopback();

    try {
        $response = makeGuzzleClient(['verify' => false])->get("https://127.0.0.1:{$port}/");

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe(TLS_BODY);
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('connects with verify pointing at the exported certificate', function () {
    [$server, $port, $certPath, $keyPath] = startTlsLoopback();

    try {
        $response = makeGuzzleClient(['verify' => $certPath])->get("https://127.0.0.1:{$port}/");

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe(TLS_BODY);
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});

it('returns the raw gzip body when decoding is disabled', function () {
    [$server, $port, $certPath, $keyPath] = startTlsLoopback();

    try {
        $decoded = makeGuzzleClient(['verify' => false])->get("https://127.0.0.1:{$port}/gzip");

        expect((string) $decoded->getBody())->toBe(GZIP_BODY)
            ->and($decoded->hasHeader('content-encoding'))->toBeFalse();

        $raw = makeGuzzleClient(['verify' => false, 'decode_content' => false])
            ->get("https://127.0.0.1:{$port}/gzip");

        $rawBody = (string) $raw->getBody();

        expect($raw->getHeaderLine('content-encoding'))->toBe('gzip')
            ->and($rawBody)->not->toBe(GZIP_BODY)
            ->and(gzdecode($rawBody))->toBe(GZIP_BODY);
    } finally {
        $server->stop();
        @unlink($certPath);
        @unlink($keyPath);
    }
});
