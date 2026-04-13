<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server;

interface RequestHandler
{
    public function handleRequest(Request $request): Response;
}
