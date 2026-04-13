<?php

use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request;

function httpbinUrl(string $path = ''): string
{
    return rtrim(test_env('FLEDGE_TEST_HTTPBIN_URL', 'http://127.0.0.1:18080'), '/').$path;
}

function httpbinAvailable(): bool
{
    $parts = parse_url(test_env('FLEDGE_TEST_HTTPBIN_URL', 'http://127.0.0.1:18080'));
    $sock = @fsockopen($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 80, $errno, $errstr, 1);
    if (! $sock) {
        return false;
    }
    fclose($sock);

    return true;
}

uses()->beforeEach(function () {
    if (! httpbinAvailable()) {
        $this->markTestSkipped('httpbin not available at '.test_env('FLEDGE_TEST_HTTPBIN_URL', 'http://127.0.0.1:18080'));
    }
});

it('GET /get returns 200', function () {
    $client = HttpClientBuilder::buildDefault();
    $request = new Request(httpbinUrl('/get'));

    $response = $client->request($request);

    expect($response->getStatus())->toBe(200);
});

it('POST /post echoes the body back', function () {
    $client = HttpClientBuilder::buildDefault();
    $request = new Request(httpbinUrl('/post'), 'POST', 'hello from fledge');

    $response = $client->request($request);

    expect($response->getStatus())->toBe(200);

    $body = $response->getBody()->buffer();
    $json = json_decode($body, true);

    expect($json['data'])->toBe('hello from fledge');
});

it('GET /status/404 returns 404', function () {
    $client = (new HttpClientBuilder)
        ->followRedirects(0)
        ->build();

    $request = new Request(httpbinUrl('/status/404'));

    $response = $client->request($request);

    expect($response->getStatus())->toBe(404);
});

it('sends custom headers', function () {
    $client = HttpClientBuilder::buildDefault();
    $request = new Request(httpbinUrl('/headers'));
    $request->setHeader('X-Fledge-Test', 'integration');

    $response = $client->request($request);

    expect($response->getStatus())->toBe(200);

    $body = $response->getBody()->buffer();
    $json = json_decode($body, true);

    expect($json['headers']['X-Fledge-Test'])->toBe('integration');
});

it('response body is readable', function () {
    $client = HttpClientBuilder::buildDefault();
    $request = new Request(httpbinUrl('/get'));

    $response = $client->request($request);
    $body = $response->getBody()->buffer();

    expect($body)->toBeString();
    expect(strlen($body))->toBeGreaterThan(0);

    $json = json_decode($body, true);
    expect($json)->toBeArray();
    expect($json)->toHaveKey('url');
});
