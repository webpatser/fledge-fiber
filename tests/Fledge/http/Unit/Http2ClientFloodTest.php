<?php

declare(strict_types=1);

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\Future;
use Fledge\Async\Http\Client\Connection\Http2Connection;
use Fledge\Async\Http\Client\Connection\HttpStream;
use Fledge\Async\Http\Client\Connection\Internal\Http2ConnectionProcessor;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\SocketException;
use Fledge\Async\Http\HPack;
use Fledge\Async\Http\Http2\Http2Parser;
use Fledge\Async\NullCancellation;
use Fledge\Async\Stream\ReadableStreamIteratorAggregate;
use Fledge\Async\Stream\ResourceSocket;
use Fledge\Async\Stream\Socket;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use Fledge\Async\Stream\TlsState;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Http\Client\events;
use function Fledge\Async\Stream\createSocketPair;

/**
 * Starts a real HTTP/2 client connection processor on one end of a socket pair; the test plays the server on the
 * other end with hand-built frames.
 *
 * @return array{Http2ConnectionProcessor, ResourceSocket, Http2Connection}
 */
function startH2ClientProcessor(
    int $maxQueuedControlFrames = Http2ConnectionProcessor::DEFAULT_MAX_QUEUED_CONTROL_FRAMES,
    ?Closure $wrapClientSocket = null,
): array {
    \Fledge\Loop::setErrorHandler(null);

    [$clientSide, $serverSide] = createSocketPair();

    $processor = new Http2ConnectionProcessor(
        $wrapClientSocket !== null ? $wrapClientSocket($clientSide) : $clientSide,
        $maxQueuedControlFrames,
    );

    $serverSide->write(Http2Parser::compileFrame('', Http2Parser::SETTINGS, 0, 0));
    $processor->initialize(new TimeoutCancellation(3));

    // Only supplies addresses to the HttpStream; it is never initialized and never used for I/O.
    $connection = new Http2Connection($clientSide, 0, null);

    return [$processor, $serverSide, $connection];
}

/** Sends a GET request on a new client stream; the returned future resolves with the response. */
function sendH2ClientRequest(
    Http2ConnectionProcessor $processor,
    Http2Connection $connection,
    Cancellation $cancellation = new NullCancellation,
): Future {
    $request = new Request('https://localhost/');

    events()->requestStart($request);
    $processor->reserveStream();
    events()->connectionAcquired($request, $connection, 1);

    $stream = HttpStream::fromConnection($connection, $processor->request(...), $processor->unreserveStream(...));

    return async(fn () => $stream->request($request, $cancellation));
}

/**
 * Reads frames sent by the client until $target frames matched $match; returns the number of matches.
 *
 * @param  callable(int $type, int $flags):bool  $match
 */
function countH2ClientFrames(ResourceSocket $server, callable $match, int $target, float $timeout = 5.0): int
{
    $cancellation = new TimeoutCancellation($timeout);
    $buffer = '';
    $matches = 0;
    $prefaceSkipped = false;

    try {
        while ($matches < $target && null !== $chunk = $server->read($cancellation)) {
            $buffer .= $chunk;

            // The client opens with the connection preface, which is not a frame.
            if (! $prefaceSkipped) {
                if (strlen($buffer) < strlen(Http2Parser::PREFACE)) {
                    continue;
                }

                $buffer = substr($buffer, strlen(Http2Parser::PREFACE));
                $prefaceSkipped = true;
            }

            while (strlen($buffer) >= 9) {
                $head = unpack('Nlen/cflags/Nid', $buffer);
                $length = ($head['len'] >> 8) & 0xffffff;

                if (strlen($buffer) < 9 + $length) {
                    break;
                }

                if ($match($head['len'] & 0xff, $head['flags'] & 0xff)) {
                    $matches++;
                }

                $buffer = substr($buffer, 9 + $length);
            }
        }
    } catch (\Throwable) {
        // Timeout or closed socket: report what was counted.
    }

    return $matches;
}

/** Wraps a socket and counts the write() calls made on it. */
function countingH2Socket(Socket $inner): Socket
{
    return new class($inner) implements IteratorAggregate, Socket
    {
        use ReadableStreamIteratorAggregate;

        public int $writes = 0;

        public function __construct(private readonly Socket $inner) {}

        public function write(string $bytes): void
        {
            $this->writes++;
            $this->inner->write($bytes);
        }

        public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string
        {
            return $this->inner->read($cancellation, $limit);
        }

        public function end(): void
        {
            $this->inner->end();
        }

        public function isWritable(): bool
        {
            return $this->inner->isWritable();
        }

        public function isReadable(): bool
        {
            return $this->inner->isReadable();
        }

        public function close(): void
        {
            $this->inner->close();
        }

        public function isClosed(): bool
        {
            return $this->inner->isClosed();
        }

        public function onClose(Closure $onClose): void
        {
            $this->inner->onClose($onClose);
        }

        public function getLocalAddress(): SocketAddress
        {
            return $this->inner->getLocalAddress();
        }

        public function getRemoteAddress(): SocketAddress
        {
            return $this->inner->getRemoteAddress();
        }

        public function setupTls(?Cancellation $cancellation = null): void
        {
            $this->inner->setupTls($cancellation);
        }

        public function shutdownTls(?Cancellation $cancellation = null): void
        {
            $this->inner->shutdownTls($cancellation);
        }

        public function isTlsConfigurationAvailable(): bool
        {
            return $this->inner->isTlsConfigurationAvailable();
        }

        public function getTlsState(): TlsState
        {
            return $this->inner->getTlsState();
        }

        public function getTlsInfo(): ?TlsInfo
        {
            return $this->inner->getTlsInfo();
        }
    };
}

function waitUntilClosed(Http2ConnectionProcessor $processor, float $timeout = 3.0): bool
{
    $deadline = microtime(true) + $timeout;

    while (! $processor->isClosed() && microtime(true) < $deadline) {
        delay(0.01);
    }

    return $processor->isClosed();
}

function expectEnhanceYourCalm(Future $response): void
{
    expect(fn () => $response->await(new TimeoutCancellation(3)))
        ->toThrow(function (SocketException $exception) {
            expect($exception->getCode())->toBe(Http2Parser::ENHANCE_YOUR_CALM);
        });
}

it('closes the connection with ENHANCE_YOUR_CALM when a server that does not read floods frames that need an ACK', function (string $frame) {
    [$processor, $server, $connection] = startH2ClientProcessor();

    try {
        $response = sendH2ClientRequest($processor, $connection);
        delay(0.05);

        $batch = str_repeat($frame, 1000);

        // The server never reads, so once the socket buffer is full every ACK stays in the client's write queue.
        // 60 batches is far more than any kernel socket buffer plus the queue limit.
        try {
            for ($i = 0; $i < 60 && ! $processor->isClosed(); $i++) {
                $server->write($batch);
            }
        } catch (\Throwable) {
            // The client closed the socket while we were writing.
        }

        expectEnhanceYourCalm($response);
        expect(waitUntilClosed($processor))->toBeTrue();
    } finally {
        $server->close();
    }
})->with([
    'PING' => Http2Parser::compileFrame('12345678', Http2Parser::PING, 0, 0),
    'SETTINGS' => Http2Parser::compileFrame('', Http2Parser::SETTINGS, 0, 0),
])->group('Http2');

it('closes the connection with ENHANCE_YOUR_CALM when a server that does not read floods refused push promises', function () {
    [$processor, $server, $connection] = startH2ClientProcessor(maxQueuedControlFrames: 10);

    try {
        // The client has no push handler, so every push promise on stream 1 is answered with RST_STREAM.
        $response = sendH2ClientRequest($processor, $connection);
        delay(0.05);

        $hpack = new HPack;
        $pushes = '';

        for ($pushId = 2; $pushId <= 100; $pushId += 2) {
            $headers = $hpack->encode([
                [':method', 'GET'],
                [':scheme', 'https'],
                [':authority', 'localhost'],
                [':path', '/pushed/'.$pushId],
            ]);

            $pushes .= Http2Parser::compileFrame(pack('N', $pushId).$headers, Http2Parser::PUSH_PROMISE, Http2Parser::END_HEADERS, 1);
        }

        // All 50 promises arrive in one read, so the write fiber cannot take the RST_STREAM replies in between.
        try {
            $server->write($pushes);
        } catch (\Throwable) {
            // The client closed the socket while we were writing.
        }

        expectEnhanceYourCalm($response);
        expect(waitUntilClosed($processor))->toBeTrue();
    } finally {
        $server->close();
    }
})->group('Http2');

it('answers a large burst of PINGs from a server that reads the replies', function () {
    [$processor, $server] = startH2ClientProcessor();

    try {
        $count = 2000;

        $reader = async(fn () => countH2ClientFrames(
            $server,
            fn (int $type, int $flags) => $type === Http2Parser::PING && ($flags & Http2Parser::ACK) !== 0,
            $count,
        ));

        $server->write(str_repeat(Http2Parser::compileFrame('12345678', Http2Parser::PING, 0, 0), $count));

        expect($reader->await())->toBe($count)
            ->and($processor->isClosed())->toBeFalse();
    } finally {
        $server->close();
    }
})->group('Http2');

it('writes the replies to a burst of PINGs in a few socket writes instead of one per ACK', function () {
    $client = null;
    [$processor, $server] = startH2ClientProcessor(wrapClientSocket: function (Socket $socket) use (&$client) {
        return $client = countingH2Socket($socket);
    });

    try {
        $count = 2000;

        $reader = async(fn () => countH2ClientFrames(
            $server,
            fn (int $type, int $flags) => $type === Http2Parser::PING && ($flags & Http2Parser::ACK) !== 0,
            $count,
        ));

        $writesBefore = $client->writes;

        $server->write(str_repeat(Http2Parser::compileFrame('12345678', Http2Parser::PING, 0, 0), $count));

        expect($reader->await())->toBe($count)
            ->and($processor->isClosed())->toBeFalse()
            // One write per read chunk (8 KiB, at most 481 PINGs), not one per ACK: per-ACK writes fill a Linux UNIX
            // socket buffer by write count and trip the flood limit on a server that does read.
            ->and($client->writes - $writesBefore)->toBeLessThan(50);
    } finally {
        $server->close();
    }
})->group('Http2');

it('keeps the connection open when 2000 in-flight streams are cancelled at once against a reading server', function () {
    [$processor, $server, $connection] = startH2ClientProcessor();

    try {
        $count = 2000;

        $resets = async(fn () => countH2ClientFrames(
            $server,
            fn (int $type) => $type === Http2Parser::RST_STREAM,
            $count,
        ));

        $deferred = new DeferredCancellation;
        $responses = [];

        for ($i = 0; $i < $count; $i++) {
            $responses[] = sendH2ClientRequest($processor, $connection, $deferred->getCancellation());
        }

        delay(0.2);

        $deferred->cancel();

        foreach ($responses as $response) {
            $response->ignore();
        }

        expect($resets->await())->toBe($count)
            ->and($processor->isClosed())->toBeFalse();
    } finally {
        $server->close();
    }
})->group('Http2');

it('rejects a non-positive control frame limit', function () {
    [$clientSide, $serverSide] = createSocketPair();

    try {
        expect(fn () => new Http2ConnectionProcessor($clientSide, 0))->toThrow(\ValueError::class);
    } finally {
        $clientSide->close();
        $serverSide->close();
    }
})->group('Http2');
