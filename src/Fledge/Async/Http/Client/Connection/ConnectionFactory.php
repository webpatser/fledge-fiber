<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\Http\Client\Request;
use function Fledge\Async\Http\Client\events;

interface ConnectionFactory
{
    /**
     * Creates a new connection.
     *
     * The implementation should call appropriate event handlers via {@see events()}.
     */
    public function create(Request $request, Cancellation $cancellation): Connection;
}
