<?php declare(strict_types=1);

namespace Fledge\Async\Http\Http2;

final class Http2ConnectionException extends \Exception
{
    public function __construct(string $message, int $code, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
