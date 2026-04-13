<?php

namespace Fledge\Fiber\Database\Connectors;

use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresConnectionPool;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;
use Illuminate\Database\Concerns\ParsesSearchPath;
use Illuminate\Database\Connectors\ConnectorInterface;

/**
 * Connector for Fledge-based non-blocking PostgreSQL connections.
 *
 * Creates an FledgePostgresPdo shim backed by a PostgresConnectionPool.
 * Configuration is identical to the standard pgsql driver.
 */
class FledgePostgresConnector implements ConnectorInterface
{
    use ParsesSearchPath;

    public function connect(array $config): FledgePostgresPdo
    {
        $pgConfig = $this->buildConfig($config);
        $pool = $this->createPool($pgConfig, $config);

        $pdo = new FledgePostgresPdo($pool);

        $this->configureConnection($pool, $config);

        return $pdo;
    }

    protected function buildConfig(array $config): PostgresConfig
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 5432);
        $database = $config['connect_via_database'] ?? $config['database'] ?? null;
        $actualPort = $config['connect_via_port'] ?? $port;

        return new PostgresConfig(
            host: $host,
            port: (int) $actualPort,
            user: $config['username'] ?? null,
            password: $config['password'] ?? null,
            database: $database,
            applicationName: $config['application_name'] ?? null,
            sslMode: $config['sslmode'] ?? null,
        );
    }

    protected function createPool(PostgresConfig $config, array $appConfig): PostgresConnectionPool
    {
        $maxConnections = (int) ($appConfig['pool_size'] ?? PostgresConnectionPool::DEFAULT_MAX_CONNECTIONS);
        $idleTimeout = (int) ($appConfig['pool_idle_timeout'] ?? PostgresConnectionPool::DEFAULT_IDLE_TIMEOUT);

        return new PostgresConnectionPool($config, $maxConnections, $idleTimeout);
    }

    protected function configureConnection(PostgresConnectionPool $pool, array $config): void
    {
        if (isset($config['isolation_level'])) {
            $pool->query(sprintf(
                'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL %s',
                $config['isolation_level']
            ));
        }

        if (isset($config['timezone'])) {
            $pool->query(sprintf("SET TIME ZONE '%s'", $config['timezone']));
        }

        if (isset($config['search_path']) || isset($config['schema'])) {
            $searchPath = $this->quoteSearchPath(
                $this->parseSearchPath($config['search_path'] ?? $config['schema'])
            );

            $pool->query(sprintf('SET search_path TO %s', $searchPath));
        }

        if (isset($config['synchronous_commit'])) {
            $pool->query(sprintf(
                "SET synchronous_commit TO '%s'",
                $config['synchronous_commit']
            ));
        }

        if (isset($config['charset'])) {
            $pool->query(sprintf("SET NAMES '%s'", $config['charset']));
        }
    }

    protected function quoteSearchPath(array $searchPath): string
    {
        return count($searchPath) === 1
            ? '"'.$searchPath[0].'"'
            : '"'.implode('", "', $searchPath).'"';
    }
}
