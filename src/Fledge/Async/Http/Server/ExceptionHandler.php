<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server;

use Fledge\Async\Http\Server\Middleware\ExceptionHandlerMiddleware;

interface ExceptionHandler
{
    /**
     * Handles an uncaught exception from the {@see RequestHandler} wrapped with {@see ExceptionHandlerMiddleware}.
     */
    public function handleException(Request $request, \Throwable $exception): Response;
}
