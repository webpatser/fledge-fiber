<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Middleware;

enum ForwardedHeaderType
{
    case Forwarded;
    case XForwardedFor;

    /**
     * @return non-empty-string
     */
    public function getHeaderName(): string
    {
        return match ($this) {
            self::Forwarded => 'forwarded',
            self::XForwardedFor => 'x-forwarded-for',
        };
    }
}
