<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\RedisException;

/**
 * Issues CLIENT SETNAME on every new connection, mirroring the phpredis
 * 'name' option handled by the upstream connector.
 */
final readonly class ClientNameSetter implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private string $clientName,
        private RedisConnector $connector,
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $connection = $this->connector->connect($cancellation);

        $connection->send('CLIENT', 'SETNAME', $this->clientName);

        if (!($connection->receive()?->unwrap())) {
            throw new RedisException('Failed to set client name: ' . $connection->getName());
        }

        return $connection;
    }
}
