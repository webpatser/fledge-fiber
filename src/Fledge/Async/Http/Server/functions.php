<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server;

use Fledge\Async\Http\HttpStatus;

function redirectTo(string $uri, int $statusCode = HttpStatus::SEE_OTHER): Response
{
    return new Response($statusCode, ['location' => $uri]);
}
