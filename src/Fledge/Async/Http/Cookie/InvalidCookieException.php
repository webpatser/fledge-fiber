<?php declare(strict_types=1);

namespace Fledge\Async\Http\Cookie;

final class InvalidCookieException extends \Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
