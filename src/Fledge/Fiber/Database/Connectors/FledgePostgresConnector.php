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

        return new FledgePostgresPdo($pool);
    }

    protected function buildConfig(array $config): PostgresConfig
    {
        $host = $config['host'] ?? '';
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
            options: $this->buildOptions($config),
            keepalives: isset($config['keepalives']) ? (int) $config['keepalives'] : null,
            keepalivesIdle: isset($config['keepalives_idle']) ? (int) $config['keepalives_idle'] : null,
            keepalivesInterval: isset($config['keepalives_interval']) ? (int) $config['keepalives_interval'] : null,
            keepalivesCount: isset($config['keepalives_count']) ? (int) $config['keepalives_count'] : null,
            sslCert: $config['sslcert'] ?? null,
            sslKey: $config['sslkey'] ?? null,
            sslRootCert: $config['sslrootcert'] ?? null,
        );
    }

    protected function createPool(PostgresConfig $config, array $appConfig): PostgresConnectionPool
    {
        $maxConnections = (int) ($appConfig['pool_size'] ?? PostgresConnectionPool::DEFAULT_MAX_CONNECTIONS);
        $idleTimeout = (int) ($appConfig['pool_idle_timeout'] ?? PostgresConnectionPool::DEFAULT_IDLE_TIMEOUT);

        // resetConnections stays true: the pool runs DISCARD ALL on every
        // checkout, and RESET ALL (part of DISCARD ALL) restores GUCs to the
        // startup-packet session defaults. Session settings therefore ride the
        // libpq startup packet via buildOptions() instead of SET statements,
        // which the first DISCARD ALL would wipe out.
        return new PostgresConnectionPool($config, $maxConnections, $idleTimeout);
    }

    /**
     * Compose the libpq startup packet options (-c name=value flags) that
     * carry the session settings, so DISCARD ALL restores them instead of
     * wiping them.
     */
    protected function buildOptions(array $config): ?string
    {
        $flags = [];

        if (isset($config['isolation_level'])) {
            $flags[] = '-c default_transaction_isolation='.$this->escapeOptionValue($config['isolation_level']);
        }

        if (isset($config['timezone'])) {
            $flags[] = '-c TimeZone='.$this->escapeOptionValue($config['timezone']);
        }

        if (isset($config['search_path']) || isset($config['schema'])) {
            $searchPath = implode(',', array_map(
                static fn (string $schema): string => '"'.$schema.'"',
                $this->parseSearchPath($config['search_path'] ?? $config['schema'])
            ));

            $flags[] = '-c search_path='.$this->escapeOptionValue($searchPath);
        }

        if (isset($config['synchronous_commit'])) {
            $flags[] = '-c synchronous_commit='.$this->escapeOptionValue($config['synchronous_commit']);
        }

        if (isset($config['charset'])) {
            $flags[] = '-c client_encoding='.$this->escapeOptionValue($config['charset']);
        }

        return $flags === [] ? null : implode(' ', $flags);
    }

    /**
     * Escape a value for use inside the libpq options string, where unescaped
     * spaces separate flags. Backslashes and spaces are backslash-escaped;
     * PostgresConfig::getConnectionString() addslashes() the rest.
     */
    protected function escapeOptionValue(string $value): string
    {
        return str_replace(['\\', ' '], ['\\\\', '\\ '], $value);
    }
}
