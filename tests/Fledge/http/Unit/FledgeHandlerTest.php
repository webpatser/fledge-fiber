<?php

use Fledge\Async\Http\Client\BufferedContent;
use Fledge\Async\Http\Client\StreamedContent;
use Fledge\Async\Http\Server\Request as ServerRequest;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Fiber\Http\FledgeHandler;
use Fledge\Fiber\Http\Psr7ReadableStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

require_once __DIR__.'/../Fixtures/loopback.php';

if (! class_exists('CountingRequestBody')) {
    /**
     * PSR-7 decorator that counts how the handler reads a request body: whole
     * body copies (__toString, getContents) versus bounded read() calls.
     */
    final class CountingRequestBody implements StreamInterface
    {
        use StreamDecoratorTrait;

        public int $wholeCopies = 0;

        public int $reads = 0;

        public int $largestRead = 0;

        private StreamInterface $stream;

        public function __construct(StreamInterface $stream)
        {
            $this->stream = $stream;
        }

        public function __toString(): string
        {
            $this->wholeCopies++;

            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->stream->getContents();
        }

        public function getContents(): string
        {
            $this->wholeCopies++;

            return $this->stream->getContents();
        }

        public function read($length): string
        {
            $chunk = $this->stream->read($length);
            $this->reads++;
            $this->largestRead = max($this->largestRead, strlen($chunk));

            return $chunk;
        }
    }
}

/**
 * Drain an async request body the way the HTTP/1 connection does.
 *
 * @return list<string>
 */
function drainAsyncRequestBody(\Fledge\Async\Http\Client\HttpContent $content): array
{
    $stream = $content->getContent();
    $chunks = [];

    while (($chunk = $stream->read()) !== null) {
        $chunks[] = $chunk;
    }

    return $chunks;
}

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

    // Guzzle defaults to no timeout and no body cap, so the async client's
    // 10 s / 10 MiB defaults must not leak through.
    expect($asyncRequest->getUri()->__toString())->toBe('https://example.com')
        ->and($asyncRequest->getMethod())->toBe('GET')
        ->and($asyncRequest->getTransferTimeout())->toBe(0.0)
        ->and($asyncRequest->getInactivityTimeout())->toBe(0.0)
        ->and($asyncRequest->getTcpConnectTimeout())->toBe(FledgeHandler::NO_CONNECT_TIMEOUT)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe(FledgeHandler::NO_CONNECT_TIMEOUT)
        ->and($asyncRequest->getBodySizeLimit())->toBe(PHP_INT_MAX);
});

it('does not cap the body size without max_body_size', function (array $options) {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $asyncRequest = $method->invoke($handler, new Request('GET', 'https://example.com'), $options);

    expect($asyncRequest->getBodySizeLimit())->toBe(PHP_INT_MAX)
        ->and($asyncRequest->getBodySizeLimit())->toBeGreaterThan(\Fledge\Async\Http\Client\Request::DEFAULT_BODY_SIZE_LIMIT);
})->with([
    'absent' => [[]],
    'zero' => [['max_body_size' => 0]],
]);

it('maps read_timeout to the inactivity timeout', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $asyncRequest = $method->invoke($handler, new Request('GET', 'https://example.com'), [
        'timeout' => 60,
        'read_timeout' => 5,
    ]);

    expect($asyncRequest->getTransferTimeout())->toBe(60.0)
        ->and($asyncRequest->getInactivityTimeout())->toBe(5.0);
});

it('maps read_timeout without an overall timeout', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $asyncRequest = $method->invoke($handler, new Request('GET', 'https://example.com'), [
        'read_timeout' => 7.5,
    ]);

    expect($asyncRequest->getTransferTimeout())->toBe(0.0)
        ->and($asyncRequest->getInactivityTimeout())->toBe(7.5);
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

it('maps an explicit http/2 protocol version to h2 with a 1.1 fallback', function (string $version) {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com', [], null, $version);

    $asyncRequest = $method->invoke($handler, $psr7Request, []);

    // ALPN negotiates h2 where available; plain-http targets and servers
    // without h2 fall back to HTTP/1.1 instead of forcing prior knowledge.
    expect($asyncRequest->getProtocolVersions())->toBe(['2', '1.1']);
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

it('bounds connect and handshake by timeout without a connect_timeout', function (array $options) {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, $options);

    // Guzzle and curl count connect time inside timeout, so a black-holed
    // host must not outlive it while the transfer timer has not started.
    expect($asyncRequest->getTransferTimeout())->toBe(5.0)
        ->and($asyncRequest->getTcpConnectTimeout())->toBe(5.0)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe(5.0);
})->with([
    'connect_timeout absent' => [['timeout' => 5]],
    'connect_timeout zero' => [['timeout' => 5, 'connect_timeout' => 0]],
]);

it('prefers an explicit connect_timeout over timeout', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, ['timeout' => 30, 'connect_timeout' => 2]);

    expect($asyncRequest->getTcpConnectTimeout())->toBe(2.0)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe(2.0);
});

it('caps connect_timeout at timeout like curl', function (array $options, float $expected) {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, $options);

    expect($asyncRequest->getTcpConnectTimeout())->toBe($expected)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe($expected);
})->with([
    'connect_timeout above timeout' => [['timeout' => 5, 'connect_timeout' => 10], 5.0],
    'connect_timeout below timeout' => [['timeout' => 30, 'connect_timeout' => 10], 10.0],
]);

it('sets body size limit from max_body_size option', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, ['max_body_size' => 1048576]);

    expect($asyncRequest->getBodySizeLimit())->toBe(1048576);
});

it('treats zero timeouts as no timeout like guzzle', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $psr7Request = new Request('GET', 'https://example.com');

    $asyncRequest = $method->invoke($handler, $psr7Request, [
        'timeout' => 0,
        'connect_timeout' => 0,
    ]);

    // Transfer and inactivity timeouts of 0 are disabled in the client; a 0
    // connect_timeout means curl's built-in 300 s connect timeout.
    expect($asyncRequest->getTransferTimeout())->toBe(0.0)
        ->and($asyncRequest->getInactivityTimeout())->toBe(0.0)
        ->and($asyncRequest->getTcpConnectTimeout())->toBe(FledgeHandler::NO_CONNECT_TIMEOUT)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe(FledgeHandler::NO_CONNECT_TIMEOUT);
});

it('uses curl\'s 300 s connect default without a connect_timeout', function (array $options) {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $asyncRequest = $method->invoke($handler, new Request('GET', 'https://example.com'), $options);

    // A timeout above 300 s does not lift curl's connect default.
    expect(FledgeHandler::NO_CONNECT_TIMEOUT)->toBe(300.0)
        ->and($asyncRequest->getTcpConnectTimeout())->toBe(300.0)
        ->and($asyncRequest->getTlsHandshakeTimeout())->toBe(300.0);
})->with([
    'no timeouts' => [[]],
    'null connect_timeout' => [['connect_timeout' => null]],
    'timeout above the default' => [['timeout' => 900]],
    'timeout above the default, connect_timeout zero' => [['timeout' => 900, 'connect_timeout' => 0]],
]);

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

    // Should not throw, just silently does nothing
    $method->invoke($handler, $request, [], null, microtime(true));

    expect(true)->toBeTrue();
});

it('streams a large request body in bounded chunks instead of copying it whole', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $payload = random_bytes(3_000_000);
    $body = new CountingRequestBody(Utils::streamFor($payload));

    // Guzzle's PrepareBodyMiddleware stamps the Content-Length before the handler runs.
    $psr7Request = new Request('PUT', 'https://example.com/upload', [
        'Content-Type' => 'application/octet-stream',
        'Content-Length' => '3000000',
    ], $body);

    $asyncRequest = $method->invoke($handler, $psr7Request, []);
    $content = $asyncRequest->getBody();

    // Building the request copies nothing.
    expect($body->wholeCopies)->toBe(0)
        ->and($body->reads)->toBe(0)
        ->and($content)->toBeInstanceOf(StreamedContent::class)
        ->and($content->getContentLength())->toBe(3_000_000)
        ->and($content->getContentType())->toBe('application/octet-stream');

    $chunks = drainAsyncRequestBody($content);

    // curl's upload buffer is 64 KiB: the largest string held is one chunk.
    expect($body->wholeCopies)->toBe(0)
        ->and($body->largestRead)->toBe(Psr7ReadableStream::CHUNK_SIZE)
        ->and(max(array_map('strlen', $chunks)))->toBeLessThanOrEqual(65536)
        ->and(count($chunks))->toBe((int) ceil(3_000_000 / 65536))
        ->and(implode('', $chunks) === $payload)->toBeTrue();
});

it('streams an unknown-size chunked request body without copying it whole', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $parts = [str_repeat('a', 100_000), str_repeat('b', 150_000), str_repeat('c', 50_000)];
    $source = $parts;
    $body = new CountingRequestBody(new PumpStream(function () use (&$source) {
        return array_shift($source) ?? false;
    }));

    $psr7Request = new Request('POST', 'https://example.com/upload', [
        'Transfer-Encoding' => 'chunked',
    ], $body);

    $asyncRequest = $method->invoke($handler, $psr7Request, []);
    $content = $asyncRequest->getBody();

    expect($body->wholeCopies)->toBe(0)
        ->and($content)->toBeInstanceOf(StreamedContent::class)
        ->and($content->getContentLength())->toBeNull();

    $chunks = drainAsyncRequestBody($content);

    expect($body->wholeCopies)->toBe(0)
        ->and($body->largestRead)->toBeLessThanOrEqual(65536)
        ->and(implode('', $chunks))->toBe(implode('', $parts));
});

it('keeps bodies below curl\'s 1 MB string threshold buffered', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $payload = str_repeat('x', 999_999);

    $asyncRequest = $method->invoke($handler, new Request('POST', 'https://example.com', [
        'Content-Length' => '999999',
    ], $payload), []);

    expect($asyncRequest->getBody())->toBeInstanceOf(BufferedContent::class)
        ->and($asyncRequest->getBody()->getContentLength())->toBe(999_999);
});

it('streams bodies from curl\'s 1 MB threshold upward', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $asyncRequest = $method->invoke($handler, new Request('POST', 'https://example.com', [
        'Content-Length' => '1000000',
    ], str_repeat('x', 1_000_000)), []);

    expect($asyncRequest->getBody())->toBeInstanceOf(StreamedContent::class)
        ->and($asyncRequest->getBody()->getContentLength())->toBe(1_000_000);
});

it('rewinds a seekable body before streaming it', function () {
    $handler = new FledgeHandler;
    $method = new ReflectionMethod($handler, 'createAsyncRequest');

    $payload = random_bytes(1_500_000);
    $stream = Utils::streamFor($payload);
    $stream->seek(0, SEEK_END);

    $asyncRequest = $method->invoke($handler, new Request('POST', 'https://example.com', [
        'Content-Length' => '1500000',
    ], $stream), []);

    expect(implode('', drainAsyncRequestBody($asyncRequest->getBody())) === $payload)->toBeTrue();
});

it('sends large request bodies byte-identical over the wire', function (string $kind) {
    $received = new ArrayObject;

    [$server, $port] = startLoopbackServer(null, function (ServerRequest $request) use ($received): ServerResponse {
        $request->getBody()->increaseSizeLimit(8_000_000);
        $body = $request->getBody()->buffer();

        $received[] = [
            'path' => $request->getUri()->getPath(),
            'sha1' => sha1($body),
            'length' => strlen($body),
            'content-length' => $request->getHeader('content-length'),
            'transfer-encoding' => $request->getHeader('transfer-encoding'),
        ];

        return $request->getUri()->getPath() === '/307'
            ? new ServerResponse(307, ['location' => '/target'])
            : new ServerResponse(200, [], 'ok');
    });

    $payload = random_bytes(2_500_000);

    $body = match ($kind) {
        'string' => $payload,
        'resource' => (function () use ($payload) {
            $handle = fopen('php://temp', 'w+b');
            fwrite($handle, $payload);
            rewind($handle);

            return $handle;
        })(),
        'pump' => (function () use ($payload) {
            $offset = 0;

            return new PumpStream(function (int $length) use ($payload, &$offset) {
                if ($offset >= strlen($payload)) {
                    return false;
                }

                $chunk = substr($payload, $offset, 100_000);
                $offset += strlen($chunk);

                return $chunk;
            });
        })(),
    };

    // The pump stream has no size: Guzzle sends it chunked. 307 replays need a seekable body.
    $path = $kind === 'pump' ? '/plain' : '/307';

    try {
        $response = makeGuzzleClient()->post("http://127.0.0.1:{$port}{$path}", ['body' => $body]);

        $entries = $received->getArrayCopy();
        $last = end($entries);

        expect($response->getStatusCode())->toBe(200)
            ->and($entries)->toHaveCount($kind === 'pump' ? 1 : 2)
            ->and(array_column($entries, 'sha1'))->each->toBe(sha1($payload))
            ->and($last['length'])->toBe(2_500_000)
            ->and($last['content-length'])->toBe($kind === 'pump' ? null : '2500000')
            ->and($last['transfer-encoding'])->toBe($kind === 'pump' ? 'chunked' : null);
    } finally {
        $server->stop();
    }
})->with(['string', 'resource', 'pump']);

it('closes the connection when the request body fails midway', function (string $failure) {
    $received = new ArrayObject;

    [$server, $port] = startLoopbackServer(null, function (ServerRequest $request) use ($received): ServerResponse {
        $request->getBody()->increaseSizeLimit(8_000_000);
        $remote = $request->getClient()->getRemoteAddress()->toString();

        try {
            $request->getBody()->buffer();
            $received[] = ['path' => $request->getUri()->getPath(), 'remote' => $remote, 'body' => 'complete'];
        } catch (\Throwable) {
            $received[] = ['path' => $request->getUri()->getPath(), 'remote' => $remote, 'body' => 'eof'];
        }

        return new ServerResponse(200, [], 'ok');
    });

    [$body, $headers] = match ($failure) {
        // Unknown size, so Guzzle sends it chunked; the second pump throws.
        'body stream throws' => [(function () {
            $pumps = 0;

            return new PumpStream(function () use (&$pumps) {
                if (++$pumps > 1) {
                    throw new \RuntimeException('body source failed');
                }

                return str_repeat('a', 200_000);
            });
        })(), []],
        'content-length larger than the body' => [str_repeat('b', 1_200_000), ['Content-Length' => '1300000']],
    };

    // One connection per host: a leaked busy connection blocks the next request.
    $client = makeGuzzleClient(['timeout' => 3], new FledgeHandler(new \Fledge\Fiber\Http\AsyncClientFactory(1)));

    try {
        // Guzzle's default timeout of 0: nothing but the fix ends the half-written request.
        expect(fn () => $client->post("http://127.0.0.1:{$port}/upload", ['body' => $body, 'headers' => $headers, 'timeout' => 0]))
            ->toThrow(\GuzzleHttp\Exception\RequestException::class);

        // The server must see the half-written request end, not wait for more bytes.
        $deadline = microtime(true) + 3;

        while (count($received) === 0 && microtime(true) < $deadline) {
            \Fledge\Async\delay(0.02);
        }

        $entries = $received->getArrayCopy();

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['body'])->toBe('eof');

        $response = $client->get("http://127.0.0.1:{$port}/next");
        $entries = $received->getArrayCopy();

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('ok')
            ->and($entries)->toHaveCount(2)
            ->and($entries[1]['remote'])->not->toBe($entries[0]['remote']);
    } finally {
        $server->stop();
    }
})->with(['body stream throws', 'content-length larger than the body']);
