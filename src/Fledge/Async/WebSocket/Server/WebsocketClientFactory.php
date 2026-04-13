<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Server;

use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\Response;
use Fledge\Async\Stream\Socket;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;
use Fledge\Async\WebSocket\WebsocketClient;

interface WebsocketClientFactory
{
    /**
     * Creates a {@see WebsocketClient} after the upgrade response has been sent to the client.
     */
    public function createClient(
        Request $request,
        Response $response,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext,
    ): WebsocketClient;
}
