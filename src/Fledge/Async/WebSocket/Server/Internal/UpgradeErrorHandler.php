<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Server\Internal;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\Response;

/** @internal */
final class UpgradeErrorHandler implements ErrorHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
    {
        return new Response(
            status: $status,
            headers: ['content-type' => 'text/plain; charset=utf-8', 'connection' => 'close'],
            body: \sprintf('%d %s', $status, $reason ?? HttpStatus::getReason($status)),
        );
    }
}
