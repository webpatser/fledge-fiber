<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\Database\SqlException;

/**
 * @implements SqlConnector<PostgresConfig, PostgresConnection>
 */
final class DefaultPostgresConnector implements SqlConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @throws SqlException If connecting fails.
     *
     * @throws \Error If neither ext-pgsql nor pecl-pq is loaded.
     */
    #[\Override]
    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): PostgresConnection
    {
        if (!$config instanceof PostgresConfig) {
            throw new \TypeError(\sprintf("Must provide an instance of %s to Postgres connectors", PostgresConfig::class));
        }

        if (\extension_loaded("pq")) {
            return PqConnection::connect($config, $cancellation);
        }

        if (\extension_loaded("pgsql")) {
            return PgSqlConnection::connect($config, $cancellation);
        }

        throw new \Error("fledge-fiber requires either pecl-pq or ext-pgsql");
    }
}
