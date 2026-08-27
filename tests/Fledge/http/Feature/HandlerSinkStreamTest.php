<?php

use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Fiber\Http\AsyncBodyStream;

require_once __DIR__.'/../Fixtures/loopback.php';

const SINK_BODY = 'streamed into the sink, chunk by chunk';

function startSinkLoopback(): array
{
    return startLoopbackServer(null, fn (): ServerResponse => new ServerResponse(200, [], SINK_BODY));
}

it('writes the response body to a path sink', function () {
    [$server, $port] = startSinkLoopback();
    $path = tempnam(sys_get_temp_dir(), 'fledge-sink-');

    try {
        $response = makeGuzzleClient()->get("http://127.0.0.1:{$port}/", ['sink' => $path]);

        expect(file_get_contents($path))->toBe(SINK_BODY)
            ->and((string) $response->getBody())->toBe(SINK_BODY);
    } finally {
        $server->stop();
        @unlink($path);
    }
});

it('writes the response body to a resource sink', function () {
    [$server, $port] = startSinkLoopback();
    $resource = fopen('php://temp', 'w+b');

    try {
        $response = makeGuzzleClient()->get("http://127.0.0.1:{$port}/", ['sink' => $resource]);

        // The response body is backed by the sink, rewound for reading.
        expect((string) $response->getBody())->toBe(SINK_BODY);
    } finally {
        $server->stop();
    }
});

it('writes the response body to a psr7 stream sink', function () {
    [$server, $port] = startSinkLoopback();
    $sink = \GuzzleHttp\Psr7\Utils::streamFor('');

    try {
        makeGuzzleClient()->get("http://127.0.0.1:{$port}/", ['sink' => $sink]);

        expect((string) $sink)->toBe(SINK_BODY);
    } finally {
        $server->stop();
    }
});

it('resolves at headers with a lazy non-seekable body when streaming', function () {
    [$server, $port] = startSinkLoopback();

    try {
        $response = makeGuzzleClient()->get("http://127.0.0.1:{$port}/", ['stream' => true]);
        $body = $response->getBody();

        expect($body)->toBeInstanceOf(AsyncBodyStream::class)
            ->and($body->isSeekable())->toBeFalse();

        // Incremental reads drain the payload chunk by chunk.
        $collected = '';

        while (! $body->eof()) {
            $piece = $body->read(8);

            expect(strlen($piece))->toBeLessThanOrEqual(8);

            $collected .= $piece;
        }

        expect($collected)->toBe(SINK_BODY);
    } finally {
        $server->stop();
    }
});

it('runs on_headers before the body arrives and wraps its failures', function () {
    [$server, $port] = startSinkLoopback();

    try {
        $seenLength = null;

        makeGuzzleClient()->get("http://127.0.0.1:{$port}/", [
            'on_headers' => function ($response) use (&$seenLength) {
                $seenLength = $response->getHeaderLine('content-length');
            },
        ]);

        expect($seenLength)->toBe((string) strlen(SINK_BODY));

        makeGuzzleClient()->get("http://127.0.0.1:{$port}/", [
            'on_headers' => function () {
                throw new RuntimeException('rejected by inspection');
            },
        ]);

        $this->fail('Expected the on_headers failure to surface');
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        expect($e->getMessage())->toContain('on_headers')
            ->and($e->getPrevious()->getMessage())->toBe('rejected by inspection');
    } finally {
        $server->stop();
    }
});
