<?php

namespace Fledge\Fiber\Database\Connectors;

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Fledge\Fiber\Database\Pdo\FledgePdoException;
use Illuminate\Database\Connectors\ConnectorInterface;

use function Fledge\Async\Database\Mysql\mysqlConnector;

/**
 * Connector for Fledge-based non-blocking MySQL connections.
 *
 * Creates an FledgeMySqlPdo shim backed by a MysqlConnectionPool.
 * Configuration is identical to the standard MySQL driver.
 */
class FledgeMySqlConnector implements ConnectorInterface
{
    /**
     * The pool opens connections lazily, so server-side connect failures (bad
     * credentials, unknown database, refused socket) surface on the first query,
     * where the shim's guard shapes them like the PDO constructor would. Anything
     * the driver raises while building the pool is shaped the same way here.
     */
    public function connect(array $config): FledgeMySqlPdo
    {
        try {
            $mysqlConfig = $this->buildConfig($config);

            return new FledgeMySqlPdo($this->createPool($mysqlConfig, $config));
        } catch (\Throwable $e) {
            throw FledgePdoException::fromThrowable($e, 'mysql');
        }
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
     * TLS is requested when any of SSL_CA, SSL_CAPATH, SSL_CERT, SSL_KEY or
     * SSL_CIPHER is present in the options, whatever its value (null, false,
     * '' and 0 included). This matches pdo_mysql, which requests TLS for each
     * of these keys on its own and then refuses a server without TLS, so
     * filtering empty values would silently downgrade to plaintext.
     * SSL_VERIFY_SERVER_CERT never requests TLS; it only modifies the
     * verification of an otherwise requested TLS context.
     *
     * Returns null (plaintext) when TLS is not requested or the connection
     * uses a unix socket.
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
        ];

        if (! array_any($sslOptions, fn ($option) => array_key_exists($option, $options))) {
            return null;
        }

        $tls = (new ClientTlsContext)->withPeerName($host);

        // Only non-empty strings reach the TLS context. An empty, null, false or 0
        // value still requests TLS (above) but leaves the setting at its default,
        // so an empty CA falls back to the system trust store, not an empty cafile.
        $ca = $this->sslOptionValue($options, \Pdo\Mysql::ATTR_SSL_CA);
        $caPath = $this->sslOptionValue($options, \Pdo\Mysql::ATTR_SSL_CAPATH);
        $cert = $this->sslOptionValue($options, \Pdo\Mysql::ATTR_SSL_CERT);
        $key = $this->sslOptionValue($options, \Pdo\Mysql::ATTR_SSL_KEY);
        $cipher = $this->sslOptionValue($options, \Pdo\Mysql::ATTR_SSL_CIPHER);

        if ($ca !== null) {
            $tls = $tls->withCaFile($ca);
        }

        if ($caPath !== null) {
            $tls = $tls->withCaPath($caPath);
        }

        if ($cert !== null) {
            $tls = $tls->withCertificate(new Certificate($cert, $key ?? $cert));
        }

        if ($cipher !== null) {
            $tls = $tls->withCiphers($cipher);
        }

        if (($config['options'][\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? true) === false) {
            $tls = $tls->withoutPeerVerification();
        }

        return (new ConnectContext)->withTlsContext($tls);
    }

    /**
     * The value of a PDO MySQL SSL option when it is a non-empty string, otherwise null.
     */
    protected function sslOptionValue(array $options, int $option): ?string
    {
        $value = $options[$option] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function createPool(MysqlConfig $config, array $appConfig): MysqlConnectionPool
    {
        $maxConnections = (int) ($appConfig['pool_size'] ?? MysqlConnectionPool::DEFAULT_MAX_CONNECTIONS);
        $idleTimeout = (int) ($appConfig['pool_idle_timeout'] ?? MysqlConnectionPool::DEFAULT_IDLE_TIMEOUT);

        $statements = $this->sessionStatements($appConfig);

        if ($statements === []) {
            return new MysqlConnectionPool($config, $maxConnections, $idleTimeout);
        }

        return new MysqlConnectionPool(
            $config,
            $maxConnections,
            $idleTimeout,
            new SessionInitializingConnector(mysqlConnector(), $statements),
        );
    }

    /**
     * Session statements to run on every new physical connection in the pool.
     *
     * @return list<string>
     */
    protected function sessionStatements(array $config): array
    {
        $statements = [];

        if (isset($config['isolation_level'])) {
            $statements[] = sprintf(
                'SET SESSION TRANSACTION ISOLATION LEVEL %s',
                $config['isolation_level']
            );
        }

        if (isset($config['timezone'])) {
            $statements[] = sprintf("SET time_zone='%s'", $config['timezone']);
        }

        if (isset($config['options'][\Pdo\Mysql::ATTR_INIT_COMMAND])) {
            $statements[] = $config['options'][\Pdo\Mysql::ATTR_INIT_COMMAND];
        }

        return $statements;
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

        $version = $config['version'] ?? null;

        if ($version !== null && version_compare($version, '8.0.11', '<')) {
            return 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
        }

        return 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
    }
}
