<?php

namespace Fledge\Fiber\Database\Connectors;

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Illuminate\Database\Connectors\ConnectorInterface;

/**
 * Connector for Fledge-based non-blocking MySQL connections.
 *
 * Creates an FledgeMySqlPdo shim backed by a MysqlConnectionPool.
 * Configuration is identical to the standard MySQL driver.
 */
class FledgeMySqlConnector implements ConnectorInterface
{
    public function connect(array $config): FledgeMySqlPdo
    {
        $mysqlConfig = $this->buildConfig($config);
        $pool = $this->createPool($mysqlConfig, $config);

        $pdo = new FledgeMySqlPdo($pool);

        $this->configureConnection($pool, $config);

        return $pdo;
    }

    protected function buildConfig(array $config): MysqlConfig
    {
        $host = ! empty($config['unix_socket']) ? $config['unix_socket'] : ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $charset = $config['charset'] ?? '';
        $collation = $config['collation'] ?? '';

        $mysqlConfig = new MysqlConfig(
            host: $host,
            port: $port,
            user: $config['username'] ?? null,
            password: $config['password'] ?? null,
            database: $config['database'] ?? null,
            context: $this->buildConnectContext($config),
            charset: $charset,
            collate: $collation,
        );

        $sqlMode = $this->getSqlMode($config);

        if ($sqlMode !== null) {
            $mysqlConfig = $mysqlConfig->withSqlMode($sqlMode);
        }

        return $mysqlConfig;
    }

    /**
     * Build the TLS-enabled connect context when PDO MySQL SSL options are configured.
     *
     * Returns null (plaintext, matching pdo_mysql defaults) when no SSL option
     * is present or the connection uses a unix socket.
     */
    protected function buildConnectContext(array $config): ?ConnectContext
    {
        $host = ! empty($config['unix_socket']) ? $config['unix_socket'] : ($config['host'] ?? '127.0.0.1');

        if (str_starts_with($host, '/')) {
            return null;
        }

        $options = $config['options'] ?? [];

        $sslOptions = [
            \Pdo\Mysql::ATTR_SSL_CA,
            \Pdo\Mysql::ATTR_SSL_CAPATH,
            \Pdo\Mysql::ATTR_SSL_CERT,
            \Pdo\Mysql::ATTR_SSL_KEY,
            \Pdo\Mysql::ATTR_SSL_CIPHER,
            \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT,
        ];

        if (! array_any($sslOptions, fn ($option) => array_key_exists($option, $options))) {
            return null;
        }

        $tls = (new ClientTlsContext)->withPeerName($host);

        if (isset($options[\Pdo\Mysql::ATTR_SSL_CA])) {
            $tls = $tls->withCaFile($options[\Pdo\Mysql::ATTR_SSL_CA]);
        }

        if (isset($options[\Pdo\Mysql::ATTR_SSL_CAPATH])) {
            $tls = $tls->withCaPath($options[\Pdo\Mysql::ATTR_SSL_CAPATH]);
        }

        if (isset($options[\Pdo\Mysql::ATTR_SSL_CERT])) {
            $cert = $options[\Pdo\Mysql::ATTR_SSL_CERT];
            $key = $options[\Pdo\Mysql::ATTR_SSL_KEY] ?? $cert;

            $tls = $tls->withCertificate(new Certificate($cert, $key));
        }

        if (isset($options[\Pdo\Mysql::ATTR_SSL_CIPHER])) {
            $tls = $tls->withCiphers($options[\Pdo\Mysql::ATTR_SSL_CIPHER]);
        }

        if (($options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? true) === false) {
            $tls = $tls->withoutPeerVerification();
        }

        return (new ConnectContext)->withTlsContext($tls);
    }

    protected function createPool(MysqlConfig $config, array $appConfig): MysqlConnectionPool
    {
        $maxConnections = (int) ($appConfig['pool_size'] ?? MysqlConnectionPool::DEFAULT_MAX_CONNECTIONS);
        $idleTimeout = (int) ($appConfig['pool_idle_timeout'] ?? MysqlConnectionPool::DEFAULT_IDLE_TIMEOUT);

        return new MysqlConnectionPool($config, $maxConnections, $idleTimeout);
    }

    protected function configureConnection(MysqlConnectionPool $pool, array $config): void
    {
        if (isset($config['isolation_level'])) {
            $pool->query(sprintf(
                'SET SESSION TRANSACTION ISOLATION LEVEL %s',
                $config['isolation_level']
            ));
        }

        if (isset($config['timezone'])) {
            $pool->query(sprintf("SET time_zone='%s'", $config['timezone']));
        }
    }

    protected function getSqlMode(array $config): ?string
    {
        if (isset($config['modes'])) {
            return implode(',', $config['modes']);
        }

        if (! isset($config['strict'])) {
            return null;
        }

        if (! $config['strict']) {
            return 'NO_ENGINE_SUBSTITUTION';
        }

        return 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
    }
}
