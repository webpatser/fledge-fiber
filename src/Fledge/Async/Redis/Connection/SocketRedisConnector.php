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
            $socketConnector = $this->socketConnector ?? Stream\socketConnector();
            $socket = $socketConnector->connect($this->uri, $this->connectContext, $cancellation);
            if ($this->connectContext->getTlsContext()) {
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
