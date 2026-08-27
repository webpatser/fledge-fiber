<?php

use Fledge\Fiber\Http\FledgeHandler;
use GuzzleHttp\Psr7\Request;

it('is callable', function () {
    expect(new FledgeHandler)->toBeCallable();
});

it('creates async request from psr7 request', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('POST', 'https://example.com/api', [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer token123',
    ], '{"key":"value"}');

    $asyncRequest = $method->invoke($handler, $psr7Request, [
        'timeout' => 30,
        'connect_timeout' => 5,
    ]);

    expect($asyncRequest->getUri()->__toString())->toBe('https://example.com/api')
        ->and($asyncRequest->getMethod())->toBe('POST')
        ->and($asyncRequest->getHeader('Content-Type'))->toBe('application/json')
        ->and($asyncRequest->getHeader('Authorization'))->toBe('Bearer token123')
        ->and($asyncRequest->getTransferTimeout())->toBe(30.0)
        ->and($asyncRequest->getTcpConnectTimeout())->toBe(5.0);
});

it('creates async request without timeouts', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, []);

    expect($asyncRequest->getUri()->__toString())->toBe('https://example.com')
        ->and($asyncRequest->getMethod())->toBe('GET');
});

it('creates async request with protocol version', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com', [], null, '1.0');

    $asyncRequest = $method->invoke($handler, $psr7Request, []);

    expect($asyncRequest->getProtocolVersions())->toBe(['1.0']);
});

it('pins the guzzle default protocol version to http/1.1', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    // Guzzle stamps PSR-7 requests "1.1" when no 'version' option is given.
    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, []);

    expect($asyncRequest->getProtocolVersions())->toBe(['1.1']);
});

it('maps an explicit http/2 protocol version to h2-only', function (string $version) {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com', [], null, $version);

    $asyncRequest = $method->invoke($handler, $psr7Request, []);

    expect($asyncRequest->getProtocolVersions())->toBe(['2']);
})->with(['2', '2.0']);

it('returns guzzle promise from invocation', function () {
    $handler = new FledgeHandler;
    $request = new Request('GET', 'https://example.com');

    $promise = $handler($request, []);

    expect($promise)->toBeInstanceOf(\GuzzleHttp\Promise\PromiseInterface::class);
});

it('copies request body to async request', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('POST', 'https://example.com', [
        'Content-Type' => 'text/plain',
    ], 'Hello World');

    $asyncRequest = $method->invoke($handler, $psr7Request, []);
    $body = $asyncRequest->getBody();

    expect($body)->toBeInstanceOf(\Fledge\Async\Http\Client\BufferedContent::class)
        ->and($body->getContentLength())->toBe(11)
        ->and($body->getContentType())->toBe('text/plain');
});

it('skips body for empty GET request', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, []);

    // Body should be the default (no explicit body set)
    expect($asyncRequest->getBody()->getContentLength())->toBe(0);
});

it('maps timeout to both transfer and inactivity timeout', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, ['timeout' => 15]);

    expect($asyncRequest->getTransferTimeout())->toBe(15.0)
        ->and($asyncRequest->getInactivityTimeout())->toBe(15.0);
});

it('maps connect_timeout to both TCP and TLS handshake timeout', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, ['connect_timeout' => 3]);

    expect($asyncRequest->getTcpConnectTimeout())->toBe(3.0)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe(3.0);
});

it('sets body size limit from max_body_size option', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, ['max_body_size' => 1048576]);

    expect($asyncRequest->getBodySizeLimit())->toBe(1048576);
});

it('does not set timeouts when they are zero', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    // Default timeouts should remain when 0 is passed
    $asyncRequest = $method->invoke($handler, $psr7Request, [
        'timeout' => 0,
        'connect_timeout' => 0,
    ]);

    // The source checks `> 0`, so 0 should NOT override defaults
    // Default transfer timeout is 10.0 in amphp/http-client
    expect($asyncRequest->getTransferTimeout())->not->toBe(0.0);
});

it('invokeStats calls on_stats callback', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'invokeStats');

    $request = new Request('GET', 'https://example.com');
    $response = new \GuzzleHttp\Psr7\Response(200, [], 'OK');

    $capturedStats = null;
    $options = [
        'on_stats' => function ($stats) use (&$capturedStats) {
            $capturedStats = $stats;
        },
    ];

    $method->invoke($handler, $request, $options, $response, microtime(true));

    expect($capturedStats)->toBeInstanceOf(\GuzzleHttp\TransferStats::class)
        ->and($capturedStats->getHandlerStats()['handler'])->toBe('fledge')
        ->and($capturedStats->getHandlerStats()['http_code'])->toBe(200);
});

it('invokeStats produces curl-shaped handler stats', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'invokeStats');

    $request = new Request('GET', 'https://example.com/path');
    $response = new \GuzzleHttp\Psr7\Response(200, [], 'OK');

    $listener = new \Fledge\Fiber\Http\TransferStatsListener(microtime(true));
    $listener->connectTime = 0.01;
    $listener->appconnectTime = 0.02;
    $listener->pretransferTime = 0.03;
    $listener->starttransferTime = 0.04;
    $listener->primaryIp = '93.184.216.34';
    $listener->primaryPort = 443;
    $listener->sizeDownload = 2;

    $capturedStats = null;
    $options = [
        'on_stats' => function ($stats) use (&$capturedStats) {
            $capturedStats = $stats;
        },
    ];

    $method->invoke($handler, $request, $options, $response, microtime(true), null, $listener);

    $handlerStats = $capturedStats->getHandlerStats();

    expect($handlerStats['namelookup_time'])->toBe(0.0)
        ->and($handlerStats['connect_time'])->toBe(0.01)
        ->and($handlerStats['appconnect_time'])->toBe(0.02)
        ->and($handlerStats['pretransfer_time'])->toBe(0.03)
        ->and($handlerStats['starttransfer_time'])->toBe(0.04)
        ->and($handlerStats['primary_ip'])->toBe('93.184.216.34')
        ->and($handlerStats['primary_port'])->toBe(443)
        ->and($handlerStats['size_download'])->toBe(2)
        ->and($handlerStats['http_code'])->toBe(200)
        ->and($handlerStats['url'])->toBe('https://example.com/path')
        ->and($handlerStats['total_time'])->toBeFloat()
        ->and($handlerStats['handler'])->toBe('fledge');
});

it('invokeStats falls back to zero values without a listener', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'invokeStats');

    $request = new Request('GET', 'https://example.com');

    $capturedStats = null;
    $options = [
        'on_stats' => function ($stats) use (&$capturedStats) {
            $capturedStats = $stats;
        },
    ];

    $method->invoke($handler, $request, $options, null, microtime(true));

    $handlerStats = $capturedStats->getHandlerStats();

    expect($handlerStats['connect_time'])->toBe(0.0)
        ->and($handlerStats['primary_ip'])->toBe('')
        ->and($handlerStats['size_download'])->toBe(0)
        ->and($handlerStats['http_code'])->toBe(0);
});

it('invokeStats does nothing without on_stats option', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'invokeStats');

    $request = new Request('GET', 'https://example.com');

    // Should not throw — just silently does nothing
    $method->invoke($handler, $request, [], null, microtime(true));

    expect(true)->toBeTrue();
});
