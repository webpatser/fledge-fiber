<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\RedisException;

final readonly class DatabaseSelector implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private int $database,
        private RedisConnector $connector
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $connection = $this->connector->connect($cancellation);

        $connection->send('SELECT', (string) $this->database);

        if (!($connection->receive()?->unwrap())) {
            throw new RedisException('Failed to select database: ' . $connection->getName());
        }

        return $connection;
    }
}
