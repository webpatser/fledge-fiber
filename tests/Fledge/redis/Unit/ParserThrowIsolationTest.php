<?php

use Fledge\Async\Redis\Connection\SocketRedisConnection;
use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\Stream\ResourceSocket;

/*
 * A wire parse failure must surface as a RedisException on receive(), never escape the
 * read fiber. The resp3 extension parser throws \Resp3\RedisException (not a Fledge
 * RedisException) on malformed framing; before the fix that escaped the EventLoop::queue
 * callback, Revolt rethrew it as UncaughtThrowable, every pending future on the
 * connection was stranded, and the socket stayed open. Seen live on dialed.at as
 * "Uncaught Resp3\RedisException ... RESP3 parse error: expected LF after CR in length".
 */

final class ThrowingParser implements ParserInterface
{
    public function __construct(private readonly \Closure $push) {}

    public function push(string $data): void
    {
        throw new \RuntimeException('RESP3 parse error: expected LF after CR in length');
    }

    public function cancel(): void {}
}

it('errors the response queue instead of killing the event loop when the parser throws', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->not->toBeFalse();
    [$clientSide, $serverSide] = $pair;

    $socket = ResourceSocket::fromServerSocket($clientSide);

    $connection = new SocketRedisConnection(
        $socket,
        static fn (\Closure $push): ParserInterface => new ThrowingParser($push),
    );

    fwrite($serverSide, "+OK\r\n");
    fclose($serverSide);

    try {
        $connection->receive();
        $this->fail('Expected RedisException to be thrown.');
    } catch (RedisException $e) {
        expect($e->getMessage())->toContain('Redis wire parse failed')
            ->and($e->getMessage())->toContain('expected LF after CR in length')
            // The offending bytes ("+OK\r\n") travel along as a hex head for diagnosis.
            ->and($e->getMessage())->toContain(bin2hex("+OK\r\n"))
            ->and($e->getPrevious())->toBeInstanceOf(\RuntimeException::class);
    }
});
