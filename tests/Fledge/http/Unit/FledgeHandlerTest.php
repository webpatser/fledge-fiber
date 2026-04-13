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

it('returns guzzle promise from invocation', function () {
    $handler = new FledgeHandler;
    $request = new Request('GET', 'https://example.com');

    $promise = $handler($request, []);

    expect($promise)->toBeInstanceOf(\GuzzleHttp\Promise\PromiseInterface::class);
});
