<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Client;

use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\Socket;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;

interface WebsocketConnectionFactory
{
    /**
     * @param Response $handshakeResponse Response that initiated the websocket connection.
     * @param Socket $socket Underlying socket to be used for network communication.
     * @param WebsocketCompressionContext|null $compressionContext CompressionContext generated from the response headers.
     */
    public function createConnection(
        Response $handshakeResponse,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext = null,
    ): WebsocketConnection;
}
