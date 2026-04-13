<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * Connector that connects to a statically defined URI instead of the URI passed to the {@code connect()} call.
 */
final readonly class StaticSocketConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private SocketAddress|string $uri,
        private SocketConnector $connector,
    ) {
    }

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket {
        return $this->connector->connect($this->uri, $context, $cancellation);
    }
}
