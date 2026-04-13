<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\Http\Client\Request;

interface ConnectionPool
{
    /**
     * Reserve a stream for a particular request.
     */
    public function getStream(Request $request, Cancellation $cancellation): Stream;
}
