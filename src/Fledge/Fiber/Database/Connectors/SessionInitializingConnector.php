<?php

namespace Fledge\Fiber\Database\Connectors;

use Fledge\Async\Cancellation;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * Connector decorator that runs session initialization statements on every
 * new physical connection.
 *
 * MysqlConnectionPool has no reset-on-checkout, so statements executed once
 * per connection persist for that connection's lifetime, mirroring how the
 * stock PDO connectors configure a single long-lived connection.
 */
final readonly class SessionInitializingConnector implements SqlConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param  list<string>  $statements
     */
    public function __construct(
        private SqlConnector $connector,
        private array $statements,
    ) {}

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqlConnection
    {
        $connection = $this->connector->connect($config, $cancellation);

        try {
            foreach ($this->statements as $statement) {
                $connection->query($statement);
            }
        } catch (\Throwable $exception) {
            $connection->close();

            throw new SqlConnectionException(
                'Failed to initialize database session: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        return $connection;
    }
}
