<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Server;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\Response;
use Fledge\Async\Stream\Socket;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;
use Fledge\Async\WebSocket\ConstantRateLimit;
use Fledge\Async\WebSocket\Parser\Rfc6455ParserFactory;
use Fledge\Async\WebSocket\Parser\WebsocketParserFactory;
use Fledge\Async\WebSocket\PeriodicHeartbeatQueue;
use Fledge\Async\WebSocket\Rfc6455Client;
use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketHeartbeatQueue;
use Fledge\Async\WebSocket\WebsocketRateLimit;
use Time\Duration;

/**
 * Creates the server side of a WebSocket connection on the accepted socket as is.
 *
 * TCP_NODELAY belongs on the listener: set it with `BindContext::withTcpNoDelay()` and every accepted
 * socket inherits it (plain and TLS alike). This factory no longer imports each client stream into
 * ext-sockets to set it.
 */
final class Rfc6455ClientFactory implements WebsocketClientFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param WebsocketHeartbeatQueue|null $heartbeatQueue Use null to disable automatic heartbeats (pings).
     * @param WebsocketRateLimit|null $rateLimit Use null to disable client rate limits.
     * @param Duration|float $closePeriod Time to wait for the peer to complete the close handshake, as a `Duration` or
     *     in seconds.
     */
    public function __construct(
        private readonly ?WebsocketHeartbeatQueue $heartbeatQueue = new PeriodicHeartbeatQueue(),
        private readonly ?WebsocketRateLimit $rateLimit = new ConstantRateLimit(),
        private readonly WebsocketParserFactory $parserFactory = new Rfc6455ParserFactory(),
        private readonly int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly Duration|float $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ) {
    }

    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext,
    ): WebsocketClient {
        return new Rfc6455Client(
            socket: $socket,
            masked: false,
            parserFactory: $this->parserFactory,
            compressionContext: $compressionContext,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimit: $this->rateLimit,
            frameSplitThreshold: $this->frameSplitThreshold,
            closePeriod: $this->closePeriod,
        );
    }
}
