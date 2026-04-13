<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Client;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\Socket;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;
use Fledge\Async\WebSocket\Parser\Rfc6455ParserFactory;
use Fledge\Async\WebSocket\Parser\WebsocketParserFactory;
use Fledge\Async\WebSocket\Rfc6455Client;
use Fledge\Async\WebSocket\WebsocketHeartbeatQueue;
use Fledge\Async\WebSocket\WebsocketRateLimit;

final class Rfc6455ConnectionFactory implements WebsocketConnectionFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly ?WebsocketHeartbeatQueue $heartbeatQueue = null,
        private readonly ?WebsocketRateLimit $rateLimit = null,
        private readonly WebsocketParserFactory $parserFactory = new Rfc6455ParserFactory(
            messageSizeLimit: Rfc6455Connection::DEFAULT_MESSAGE_SIZE_LIMIT,
            frameSizeLimit: Rfc6455Connection::DEFAULT_FRAME_SIZE_LIMIT,
        ),
        private readonly int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly float $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ) {
    }

    public function createConnection(
        Response $handshakeResponse,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext = null,
    ): WebsocketConnection {
        $client = new Rfc6455Client(
            socket: $socket,
            masked: true,
            parserFactory: $this->parserFactory,
            compressionContext: $compressionContext,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimit: $this->rateLimit,
            frameSplitThreshold: $this->frameSplitThreshold,
            closePeriod: $this->closePeriod,
        );

        return new Rfc6455Connection($client, $handshakeResponse);
    }
}
