<?php

/**
 * Raw HTTP/2 frame harness: runs a real Http2Driver on one end of an
 * in-process socket pair and lets a test write hand-built frames from the
 * other end. Every read is time-bounded.
 */

use Fledge\Async\Http\HPack;
use Fledge\Async\Http\Http2\Http2Parser;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Driver\Http2Driver;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Stream\InternetAddress;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use Fledge\Async\TimeoutCancellation;
use Psr\Log\NullLogger;

use function Fledge\Async\async;
use function Fledge\Async\Stream\createSocketPair;

if (! class_exists('H2RawPeer')) {
    final class H2RawPeer
    {
        private string $buffer = '';

        public function __construct(
            public readonly Http2Driver $driver,
            private readonly \Fledge\Async\Stream\ResourceSocket $socket,
        ) {}

        public function send(string $bytes): void
        {
            $this->socket->write($bytes);
        }

        public function sendFrame(int $type, int $flags, int $stream, string $payload = ''): void
        {
            $this->send(Http2Parser::compileFrame($payload, $type, $flags, $stream));
        }

        /** Open stream $id with a POST request that keeps the request body open. */
        public function openStream(int $id, array $extra = [], bool $endStream = false): void
        {
            $headers = [
                [':method', 'POST'],
                [':scheme', 'https'],
                [':authority', 'localhost'],
                [':path', '/'],
                ...$extra,
            ];

            $flags = Http2Parser::END_HEADERS | ($endStream ? Http2Parser::END_STREAM : 0);
            $this->sendFrame(Http2Parser::HEADERS, $flags, $id, (new HPack)->encode($headers));
        }

        /**
         * Read frames until $match returns true for one; returns it or null on timeout/EOF.
         *
         * @param  callable(array{type:int,flags:int,stream:int,payload:string}):bool  $match
         * @return array{type:int,flags:int,stream:int,payload:string}|null
         */
        public function waitFor(callable $match, float $timeout = 3.0): ?array
        {
            $cancellation = new TimeoutCancellation($timeout);

            while (true) {
                while (strlen($this->buffer) >= 9) {
                    $head = unpack('Nlen/cflags/Nid', $this->buffer);
                    $length = ($head['len'] >> 8) & 0xffffff;

                    if (strlen($this->buffer) < 9 + $length) {
                        break;
                    }

                    $frame = [
                        'type' => $head['len'] & 0xff,
                        'flags' => $head['flags'] & 0xff,
                        'stream' => $head['id'] & 0x7fffffff,
                        'payload' => substr($this->buffer, 9, $length),
                    ];
                    $this->buffer = substr($this->buffer, 9 + $length);

                    if ($match($frame)) {
                        return $frame;
                    }
                }

                try {
                    $chunk = $this->socket->read($cancellation);
                } catch (\Throwable) {
                    return null;
                }

                if ($chunk === null) {
                    return null;
                }

                $this->buffer .= $chunk;
            }
        }

        /** @return int|null The error code of the next GOAWAY frame. */
        public function goAwayCode(float $timeout = 3.0): ?int
        {
            $frame = $this->waitFor(fn (array $f) => $f['type'] === Http2Parser::GOAWAY, $timeout);

            return $frame === null ? null : unpack('Nlast/Ncode', $frame['payload'])['code'];
        }

        /** @return int|null The error code of the next RST_STREAM frame on $stream. */
        public function resetCode(int $stream, float $timeout = 3.0): ?int
        {
            $frame = $this->waitFor(
                fn (array $f) => $f['type'] === Http2Parser::RST_STREAM && $f['stream'] === $stream,
                $timeout,
            );

            return $frame === null ? null : unpack('Ncode', $frame['payload'])['code'];
        }

        public function close(): void
        {
            $this->socket->close();
        }
    }
}

if (! function_exists('startH2Driver')) {
    /** @param  array<string,mixed>  $driverArgs Named Http2Driver constructor overrides. */
    function startH2Driver(array $driverArgs = []): H2RawPeer
    {
        // A stream reset before its request is dispatched leaves the body queue's error future unobserved, which
        // the event loop reports as an uncaught throwable. That is outside what these tests check, so swallow it.
        \Revolt\EventLoop::setErrorHandler(static function (\Throwable $e): void {
            if (! $e instanceof \Fledge\Async\Internal\UnhandledFutureError) {
                throw $e;
            }
        });

        [$serverSide, $peerSide] = createSocketPair();

        $driver = new Http2Driver(
            new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, [], 'ok')),
            new DefaultErrorHandler,
            new NullLogger,
            ...$driverArgs,
        );

        static $nextClientId = 0;

        // The driver's timeout queue is keyed by client id, so every connection needs its own.
        $client = new class(++$nextClientId) implements Client
        {
            public function __construct(private readonly int $id) {}

            public function getId(): int
            {
                return $this->id;
            }

            public function getRemoteAddress(): SocketAddress
            {
                return new InternetAddress('127.0.0.1', 50000);
            }

            public function getLocalAddress(): SocketAddress
            {
                return new InternetAddress('127.0.0.1', 8443);
            }

            public function getTlsInfo(): ?TlsInfo
            {
                return null;
            }

            public function close(): void {}

            public function isClosed(): bool
            {
                return false;
            }

            public function onClose(\Closure $onClose): void {}
        };

        async(fn () => $driver->handleClient($client, $serverSide, $serverSide))->ignore();

        $peer = new H2RawPeer($driver, $peerSide);
        $peer->send(Http2Parser::PREFACE);
        $peer->sendFrame(Http2Parser::SETTINGS, 0, 0);

        return $peer;
    }
}
