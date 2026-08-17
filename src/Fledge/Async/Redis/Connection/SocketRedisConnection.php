<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Stream\ResourceStream;
use Fledge\Async\Stream\StreamException;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\ConcurrentIterator;
use Fledge\Async\Queue;
use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\Resp3ExtensionParser;
use Fledge\Async\Redis\Protocol\RespParser;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\Stream\Socket;
use Revolt\EventLoop;

final readonly class SocketRedisConnection implements RedisConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private Socket $socket;

    private string $name;

    private ConcurrentIterator $iterator;

    /**
     * @param (\Closure(\Closure(RedisResponse):void):ParserInterface)|null $parserFactory
     *     Optional factory for the wire-protocol parser. Receives the push closure
     *     that delivers each parsed reply to the connection's response queue. Defaults
     *     to constructing a RespParser. Pass an alternative factory to plug in a
     *     different parser implementation per connection (no shared global state).
     */
    public function __construct(Socket $socket, ?\Closure $parserFactory = null)
    {
        $this->socket = $socket;
        $this->name = $socket->getRemoteAddress()->toString();

        $queue = new Queue();
        $this->iterator = $queue->iterate();

        $factory = $parserFactory ?? static fn (\Closure $push): ParserInterface =>
            Resp3ExtensionParser::isUsable()
                ? new Resp3ExtensionParser($push)
                : new RespParser($push);

        EventLoop::queue(static function () use ($socket, $queue, $factory): void {
            /** @psalm-suppress InvalidArgument */
            $parser = $factory($queue->push(...));

            try {
                while (null !== $chunk = $socket->read()) {
                    try {
                        $parser->push($chunk);
                    } catch (\Throwable $e) {
                        /* A wire parse failure is not a Fledge RedisException: the
                         * resp3 extension parser throws \Resp3\RedisException, and a
                         * pure-PHP parser bug would throw Error. Anything escaping
                         * this loop kills the event-loop callback (Revolt rethrows it
                         * as UncaughtThrowable), stranding every pending future on
                         * this connection with no error and leaving the socket open.
                         * Wrap it, keep a hex head of the offending chunk so a
                         * recurrence identifies the actual bytes on the wire, and
                         * fall through to the RedisException path below. The typed
                         * RedisWireException lets ReconnectingRedisLink fail the
                         * pending commands instead of resending them onto a stream
                         * that would corrupt the same way. */
                        throw new RedisWireException(
                            'Redis wire parse failed: ' . $e->getMessage()
                            . ' (chunk head: ' . \bin2hex(\substr($chunk, 0, 64)) . ')',
                            0,
                            $e,
                        );
                    }
                }

                $parser->cancel();
                $queue->complete();
            } catch (RedisException $e) {
                $queue->error($e);
            } catch (\Throwable $e) {
                /* Socket/stream failures outside the parser (e.g. a StreamException
                 * from read()) must also error the queue rather than escape. */
                $queue->error(new RedisException('Redis connection failed: ' . $e->getMessage(), 0, $e));
            }

            $socket->close();
        });
    }

    public function receive(): ?RedisResponse
    {
        if (!$this->iterator->continue()) {
            return null;
        }

        return $this->iterator->getValue();
    }

    public function send(string ...$args): void
    {
        if ($this->socket->isClosed()) {
            throw new RedisConnectionException('Redis connection already closed');
        }

        $payload = \implode("\r\n", \array_map(fn (string $arg) => '$' . \strlen($arg) . "\r\n" . $arg, $args));
        $payload = '*' . \count($args) . "\r\n{$payload}\r\n";

        try {
            $this->socket->write($payload);
        } catch (StreamException $e) {
            throw new RedisConnectionException($e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function reference(): void
    {
        if ($this->socket instanceof ResourceStream) {
            $this->socket->reference();
        }
    }

    public function unreference(): void
    {
        if ($this->socket instanceof ResourceStream) {
            $this->socket->unreference();
        }
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function __destruct()
    {
        $this->close();
    }
}
