<?php

namespace Tests\Fledge\database\Stubs;

use Fledge\Async\Cancellation;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\ConnectException;
use Fledge\Async\Stream\Socket;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\SocketConnector;

/**
 * SocketConnector that records the URI and context it receives.
 *
 * With an inner connector it delegates and keeps the returned socket;
 * without one it refuses the connection after recording.
 */
class CapturingSocketConnector implements SocketConnector
{
    public ?string $uri = null;

    public ?ConnectContext $context = null;

    public ?Socket $socket = null;

    public function __construct(private ?SocketConnector $inner = null) {}

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket {
        $this->uri = (string) $uri;
        $this->context = $context;

        if ($this->inner === null) {
            throw new ConnectException('Connection refused by CapturingSocketConnector');
        }

        return $this->socket = $this->inner->connect($uri, $context, $cancellation);
    }
}
