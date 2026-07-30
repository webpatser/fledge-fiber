<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\RedisException;

final readonly class Authenticator implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param string $username Optional ACL username. When set, the two-argument form of AUTH is used.
     */
    public function __construct(
        #[\SensitiveParameter] private string $password,
        private RedisConnector $connector,
        private string $username = '',
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $connection = $this->connector->connect($cancellation);

        if ($this->username !== '') {
            $connection->send('AUTH', $this->username, $this->password);
        } else {
            $connection->send('AUTH', $this->password);
        }

        if (!($connection->receive()?->unwrap())) {
            throw new RedisException('Failed to authenticate to redis instance: ' . $connection->getName());
        }

        return $connection;
    }
}
