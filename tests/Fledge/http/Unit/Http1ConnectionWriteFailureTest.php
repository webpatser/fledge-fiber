<?php

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Http\Client\Connection\Http1Connection;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\StreamedContent;
use Fledge\Async\NullCancellation;
use Fledge\Async\Queue;
use Fledge\Async\Stream\ReadableIterableStream;
use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\ReadableStreamIteratorAggregate;
use Fledge\Async\Stream\Socket;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\Http\Client\events;
use function Fledge\Async\Stream\createSocketPair;

function http1WriteFailureSocketPair(): array
{
    try {
        return createSocketPair();
    } catch (\Throwable $e) {
        test()->markTestSkipped('Socket pair unavailable: '.$e->getMessage());
    }
}

function http1WriteFailureReadUntilEof(Socket $server): string
{
    $received = '';

    while (null !== $chunk = $server->read(new TimeoutCancellation(5))) {
        $received .= $chunk;
    }

    return $received;
}

it('closes the connection when the request body stream throws mid-write', function () {
    [$server, $client] = http1WriteFailureSocketPair();

    $connection = new Http1Connection($client, 0.0, null, 5.0);

    $body = new ReadableIterableStream((static function (): \Generator {
        yield 'aaaa';
        yield 'bbbb';

        throw new \RuntimeException('Request body failed');
    })());

    $request = new Request('http://localhost/upload', 'POST');
    $request->setBody(StreamedContent::fromStream($body, 100));
    events()->requestStart($request);

    $stream = $connection->getStream($request);
    expect($stream)->not->toBeNull();

    expect(fn () => $stream->request($request, new NullCancellation))
        ->toThrow(\RuntimeException::class, 'Request body failed');

    // A half-written request must never leave the connection reusable.
    expect($connection->isClosed())->toBeTrue();

    $second = new Request('http://localhost/second');
    events()->requestStart($second);
    expect($connection->getStream($second))->toBeNull();

    // The server sees exactly one partial request and then EOF.
    $received = http1WriteFailureReadUntilEof($server);

    expect(\substr_count($received, 'POST /upload'))->toBe(1)
        ->and($received)->not->toContain('/second');

    $server->close();
});

it('delivers an early response and then closes the connection when the request write fails', function () {
    [$server, $client] = http1WriteFailureSocketPair();

    $connection = new Http1Connection($client, 0.0, null, 5.0);

    $gate = new DeferredFuture();

    // Yields one chunk, then waits for the gate, sends the rest of the response body and fails.
    // The failure runs in the writing fiber, so the write catch block runs before the client sees the rest.
    $body = new class($gate, $server) implements ReadableStream, \IteratorAggregate {
        use ReadableStreamIteratorAggregate;

        private int $reads = 0;

        private bool $closed = false;

        public function __construct(private DeferredFuture $gate, private Socket $server)
        {
        }

        public function read(?Cancellation $cancellation = null): ?string
        {
            if (++$this->reads === 1) {
                return 'aaaa';
            }

            $this->gate->getFuture()->await($cancellation);
            $this->server->write('lo');

            throw new \RuntimeException('Request body failed');
        }

        public function isReadable(): bool
        {
            return !$this->closed;
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function isClosed(): bool
        {
            return $this->closed;
        }

        public function onClose(\Closure $onClose): void
        {
        }
    };

    $request = new Request('http://localhost/upload', 'POST');
    $request->setBody(StreamedContent::fromStream($body, 100));
    events()->requestStart($request);

    $stream = $connection->getStream($request);
    expect($stream)->not->toBeNull();

    // The server answers early with a keep-alive response while the client is still writing.
    $head = async(static function () use ($server): string {
        $received = '';

        while (!\str_contains($received, "\r\n\r\n")) {
            $received .= $server->read(new TimeoutCancellation(5)) ?? throw new \RuntimeException('Server saw EOF');
        }

        $server->write("HTTP/1.1 413 Payload Too Large\r\nContent-Length: 5\r\n\r\nhel");

        return $received;
    });

    $response = $stream->request($request, new NullCancellation);

    expect($response->getStatus())->toBe(413);

    $gate->complete();

    expect($response->getBody()->buffer(new TimeoutCancellation(5)))->toBe('hello');

    // The failed write must not leave the connection reusable, even after the response completed.
    expect($connection->isClosed())->toBeTrue();

    $second = new Request('http://localhost/second');
    events()->requestStart($second);
    expect($connection->getStream($second))->toBeNull();

    $received = $head->await().http1WriteFailureReadUntilEof($server);

    expect(\substr_count($received, 'POST /upload'))->toBe(1)
        ->and($received)->not->toContain('/second');

    $server->close();
});

it('closes the connection when the request is cancelled mid-write', function () {
    [$server, $client] = http1WriteFailureSocketPair();

    $connection = new Http1Connection($client, 0.0, null, 5.0);

    // The body yields one chunk and then stalls until the request is cancelled.
    $queue = new Queue();
    $queue->pushAsync('aaaa')->ignore();

    $request = new Request('http://localhost/upload', 'POST');
    $request->setBody(StreamedContent::fromStream(new ReadableIterableStream($queue->pipe()), 100));
    events()->requestStart($request);

    $stream = $connection->getStream($request);
    expect($stream)->not->toBeNull();

    $deferredCancellation = new DeferredCancellation();

    // Cancellation subscribers run on a later tick, so wait for the close event itself.
    $closed = new DeferredFuture();
    $connection->onClose(static fn () => $closed->complete());

    // Cancel once the server has seen the request head, so the cancellation lands mid-write.
    $head = async(static function () use ($server, $deferredCancellation): string {
        $chunk = $server->read(new TimeoutCancellation(5)) ?? '';
        $deferredCancellation->cancel();

        return $chunk;
    });

    expect(fn () => $stream->request($request, $deferredCancellation->getCancellation()))
        ->toThrow(\Fledge\Async\CancelledException::class);

    $closed->getFuture()->await(new TimeoutCancellation(5));

    expect($connection->isClosed())->toBeTrue();

    $second = new Request('http://localhost/second');
    events()->requestStart($second);
    expect($connection->getStream($second))->toBeNull();

    $received = $head->await().http1WriteFailureReadUntilEof($server);

    expect(\substr_count($received, 'POST /upload'))->toBe(1)
        ->and($received)->not->toContain('/second');

    $server->close();
});
