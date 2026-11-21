<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\Stream;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\SocketConnector;

final readonly class SocketRedisConnector implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    private ConnectContext $connectContext;

    /**
     * @param (\Closure(\Closure(RedisResponse):void):ParserInterface)|null $parserFactory
     *     Optional factory threaded through to each new SocketRedisConnection.
     *     Defaults to RespParser when null.
     */
    public function __construct(
        private string $uri,
        ConnectContext $connectContext,
        private ?SocketConnector $socketConnector = null,
        private ?\Closure $parserFactory = null,
        private bool $tcpKeepalive = false,
    ) {
        $this->connectContext = $connectContext;
    }

    /**
     * @throws CancelledException
     * @throws RedisException
     * @throws RedisConnectionException
     */
    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        try {
            // phpredis makes a single connect attempt; the global socketConnector()
            // retries a refused connect with 2 s and 4 s of backoff. Reconnect
            // retries belong to ReconnectingRedisLink and its own backoff.
            $socketConnector = $this->socketConnector ?? new Stream\DnsSocketConnector();
            // SO_KEEPALIVE (the phpredis tcp_keepalive option) is a socket context option, set at connect.
            // A context that already enables keep-alive keeps its own idle/interval/count tuning.
            $connectContext = $this->tcpKeepalive && !$this->connectContext->hasKeepAlive()
                ? $this->connectContext->withKeepAlive()
                : $this->connectContext;
            $socket = $socketConnector->connect($this->uri, $connectContext, $cancellation);
            if ($connectContext->getTlsContext()) {
                $socket->setupTls($cancellation);
            }
        } catch (Stream\SocketException $e) {
            throw new RedisConnectionException(
                'Failed to connect to redis instance (' . $this->uri . ')',
                0,
                $e
            );
        }

        return new SocketRedisConnection($socket, $this->parserFactory);
    }
}
