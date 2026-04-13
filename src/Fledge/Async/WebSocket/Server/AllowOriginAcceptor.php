<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Server;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\Response;

final class AllowOriginAcceptor implements WebsocketAcceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param list<string> $allowOrigins
     */
    public function __construct(
        private readonly array $allowOrigins,
        private readonly ErrorHandler $errorHandler = new Internal\UpgradeErrorHandler(),
        private readonly WebsocketAcceptor $acceptor = new Rfc6455Acceptor(),
    ) {
    }

    public function handleHandshake(Request $request): Response
    {
        if (!\in_array($request->getHeader('origin'), $this->allowOrigins, true)) {
            return $this->errorHandler->handleError(HttpStatus::FORBIDDEN, 'Origin forbidden', $request);
        }

        return $this->acceptor->handleHandshake($request);
    }
}
