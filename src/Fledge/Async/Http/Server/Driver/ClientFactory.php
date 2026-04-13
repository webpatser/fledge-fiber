<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Http\Client\SocketException;
use Fledge\Async\Stream\Socket;

interface ClientFactory
{
    /**
     * Create a client object for the given Socket, enabling TLS if necessary or configuring other socket options.
     *
     * @throws SocketException
     */
    public function createClient(Socket $socket): ?Client;
}
