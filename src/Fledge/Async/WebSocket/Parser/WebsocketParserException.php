<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Parser;

use Fledge\Async\WebSocket\WebsocketException;

final class WebsocketParserException extends WebsocketException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
