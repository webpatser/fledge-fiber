<?php

use Fledge\Async\CancelledException;
use Fledge\Async\Dns\DnsException;
use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\InvalidRequestException;
use Fledge\Async\Http\Client\ParseException;
use Fledge\Async\Http\Client\Request as AsyncRequest;
use Fledge\Async\Http\Client\SocketException as ClientSocketException;
use Fledge\Async\Http\Client\TimeoutException as ClientTimeoutException;
use Fledge\Async\Http\Client\TlsException as ClientTlsException;
use Fledge\Async\Stream\ConnectException as StreamConnectException;
use Fledge\Fiber\Http\GuzzleExceptionMapper;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function mapperRequest(): Request
{
    return new Request('GET', 'https://example.com/resource');
}

it('maps connection level exceptions to guzzle connect exceptions', function (\Throwable $e) {
    $request = mapperRequest();

    $mapped = GuzzleExceptionMapper::map($e, $request);

    expect($mapped)->toBeInstanceOf(ConnectException::class)
        ->and($mapped->getPrevious())->toBe($e)
        ->and($mapped->getRequest())->toBe($request);
})->with([
    'client socket exception' => fn () => new ClientSocketException("Connection to 'example.com:443' failed"),
    'client tls exception' => fn () => new ClientTlsException('TLS handshake failed'),
    'client timeout exception' => fn () => new ClientTimeoutException('Connection timed out'),
    'stream connect exception' => fn () => new StreamConnectException('Connection refused'),
    'dns exception' => fn () => new DnsException('DNS resolution failed for example.com'),
    'cancelled exception' => fn () => new CancelledException,
]);

it('preserves the underlying message on connect exceptions', function () {
    $e = new ClientSocketException("Connection to 'example.com:443' failed");

    $mapped = GuzzleExceptionMapper::map($e, mapperRequest());

    expect($mapped->getMessage())->toBe("Connection to 'example.com:443' failed");
});

it('maps protocol level exceptions to guzzle request exceptions', function (\Throwable $e) {
    $request = mapperRequest();

    $mapped = GuzzleExceptionMapper::map($e, $request);

    expect($mapped)->toBeInstanceOf(RequestException::class)
        ->and($mapped)->not->toBeInstanceOf(ConnectException::class)
        ->and($mapped->getPrevious())->toBe($e)
        ->and($mapped->getRequest())->toBe($request);
})->with([
    'invalid request exception' => fn () => new InvalidRequestException(new AsyncRequest('https://example.com'), 'Invalid request'),
    'parse exception' => fn () => new ParseException('Invalid response', 400),
    'generic http exception' => fn () => new HttpException('Something went sideways'),
    'plain runtime exception' => fn () => new RuntimeException('Unexpected failure'),
]);

it('attaches the response to mapped request exceptions when available', function () {
    $request = mapperRequest();
    $response = new Response(500, [], 'boom');

    $mapped = GuzzleExceptionMapper::map(new HttpException('Server exploded'), $request, $response);

    expect($mapped)->toBeInstanceOf(RequestException::class)
        ->and($mapped->getResponse())->toBe($response);
});

it('passes guzzle exceptions through unchanged', function () {
    $request = mapperRequest();
    $original = new ConnectException('Already mapped', $request);

    expect(GuzzleExceptionMapper::map($original, $request))->toBe($original);
});

it('generates a fallback message for exceptions without one', function () {
    $mapped = GuzzleExceptionMapper::map(new RuntimeException(''), mapperRequest());

    expect($mapped->getMessage())->toContain('RuntimeException');
});
