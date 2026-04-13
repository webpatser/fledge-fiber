<?php declare(strict_types=1);

namespace Fledge\Async\Database;

use Fledge\Async\Cancellation;
use Fledge\Async\CompositeException;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlConnector;

/**
 * @template TConfig of SqlConfig
 * @template TConnection of SqlConnection
 * @implements SqlConnector<TConfig, TConnection>
 */
final readonly class RetrySqlConnector implements SqlConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param SqlConnector<TConfig, TConnection> $connector
     */
    public function __construct(
        private SqlConnector $connector,
        private int $maxTries = 3,
    ) {
        if ($maxTries <= 0) {
            throw new \Error('The number of tries must be 1 or greater');
        }
    }

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqlConnection
    {
        $tries = 0;
        $exceptions = [];

        do {
            try {
                return $this->connector->connect($config, $cancellation);
            } catch (SqlConnectionException $exception) {
                $exceptions[] = $exception;
            }
        } while (++$tries < $this->maxTries);

        $name = $config->getHost() . ':' . $config->getPort();

        throw new SqlConnectionException(
            "Could not connect to database server at {$name} after {$tries} tries",
            0,
            new CompositeException($exceptions)
        );
    }
}
