<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Middleware;

use Fledge\Async\Http\Server\Middleware;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response;

final class ClosureMiddleware implements Middleware
{
    /**
     * @param \Closure(Request, RequestHandler):Response $closure Closure accepting an {@see Request} object
     * as the first argument, {@see RequestHandler} as second argument and returning an instance of {@see Response}.
     */
    public function __construct(private readonly \Closure $closure)
    {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        return ($this->closure)($request, $requestHandler);
    }
}
