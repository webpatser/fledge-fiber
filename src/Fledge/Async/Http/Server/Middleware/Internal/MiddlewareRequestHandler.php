<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Middleware\Internal;

use Fledge\Async\Http\Server\Middleware;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response;

/**
 * Wraps a request handler with a single middleware.
 *
 * @see stackMiddleware()
 * @internal
 */
final readonly class MiddlewareRequestHandler implements RequestHandler
{
    public function __construct(
        private Middleware $middleware,
        private RequestHandler $requestHandler,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        return $this->middleware->handleRequest($request, $this->requestHandler);
    }
}
