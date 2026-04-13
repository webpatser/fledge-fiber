<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Client;

use Fledge\Async\Http\Client\Response;
use Fledge\Async\WebSocket\WebsocketClient;

interface WebsocketConnection extends WebsocketClient
{
    /**
     * @return Response Server response originating the client connection.
     */
    public function getHandshakeResponse(): Response;
}
