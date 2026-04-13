<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Client;

use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\Response;

final class WebsocketConnectException extends HttpException
{
    public function __construct(
        string $message,
        private readonly Response $response,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
