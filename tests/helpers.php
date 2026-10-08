<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\FiberDatabaseServiceProvider;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

if (! function_exists('test_env')) {
    /**
     * Get an environment variable with a default fallback.
     */
    function test_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value !== false ? $value : $default;
    }
}

if (! function_exists('mariadbConfig')) {
    /**
     * Build the connection config for the MariaDB test server.
     *
     * @return array<string, mixed>
     */
    function mariadbConfig(): array
    {
        return [
            'host' => test_env('FLEDGE_TEST_MARIADB_HOST', '127.0.0.1'),
            'port' => (int) test_env('FLEDGE_TEST_MARIADB_PORT', 13307),
            'username' => test_env('FLEDGE_TEST_MARIADB_USER', 'fledge'),
            'password' => test_env('FLEDGE_TEST_MARIADB_PASSWORD', 'fledge'),
            'database' => test_env('FLEDGE_TEST_MARIADB_DATABASE', 'fledge_test'),
            'charset' => 'utf8mb4',
        ];
    }
}

if (! function_exists('mariadbAvailable')) {
    /**
     * Check whether the MariaDB test server is reachable.
     */
    function mariadbAvailable(): bool
    {
        $host = test_env('FLEDGE_TEST_MARIADB_HOST', '127.0.0.1');
        $port = (int) test_env('FLEDGE_TEST_MARIADB_PORT', 13307);
        $sock = @fsockopen($host, $port, $errno, $errstr, 1);
        if (! $sock) {
            return false;
        }
        fclose($sock);

        return true;
    }
}

if (! function_exists('mariadbConnection')) {
    /**
     * Open a connection to the MariaDB test server.
     *
     * Returns null when MariaDB is not configured or not reachable, so
     * callers can skip cleanly instead of failing.
     */
    function mariadbConnection(): ?FledgeMySqlPdo
    {
        if (! mariadbAvailable()) {
            return null;
        }

        try {
            return (new FledgeMySqlConnector)->connect(mariadbConfig());
        } catch (Throwable) {
            return null;
        }
    }
}

if (! function_exists('postgresConfig')) {
    function postgresConfig(): array
    {
        return [
            'host' => test_env('FLEDGE_TEST_POSTGRES_HOST', '127.0.0.1'),
            'port' => (int) test_env('FLEDGE_TEST_POSTGRES_PORT', 15432),
            'username' => test_env('FLEDGE_TEST_POSTGRES_USER', 'fledge'),
            'password' => test_env('FLEDGE_TEST_POSTGRES_PASSWORD', 'fledge'),
            'database' => test_env('FLEDGE_TEST_POSTGRES_DATABASE', 'fledge_test'),
        ];
    }
}

if (! function_exists('postgresAvailable')) {
    function postgresAvailable(): bool
    {
        $host = test_env('FLEDGE_TEST_POSTGRES_HOST', '127.0.0.1');
        $port = (int) test_env('FLEDGE_TEST_POSTGRES_PORT', 15432);
        $sock = @fsockopen($host, $port, $errno, $errstr, 1);
        if (! $sock) {
            return false;
        }
        fclose($sock);

        return true;
    }
}

if (! function_exists('mysqlConfig')) {
    function mysqlConfig(): array
    {
        return [
            'host' => test_env('FLEDGE_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) test_env('FLEDGE_TEST_MYSQL_PORT', 13306),
            'username' => test_env('FLEDGE_TEST_MYSQL_USER', 'fledge'),
            'password' => test_env('FLEDGE_TEST_MYSQL_PASSWORD', 'fledge'),
            'database' => test_env('FLEDGE_TEST_MYSQL_DATABASE', 'fledge_test'),
            'charset' => 'utf8mb4',
        ];
    }
}

if (! function_exists('mysqlAvailable')) {
    function mysqlAvailable(): bool
    {
        $host = test_env('FLEDGE_TEST_MYSQL_HOST', '127.0.0.1');
        $port = (int) test_env('FLEDGE_TEST_MYSQL_PORT', 13306);
        $sock = @fsockopen($host, $port, $errno, $errstr, 1);
        if (! $sock) {
            return false;
        }
        fclose($sock);

        return true;
    }
}

if (! function_exists('nativeDriverSkipReason')) {
    /**
     * Why the native driver cannot run against the given server ('mysql' or
     * 'mariadb'), or null when it can.
     */
    function nativeDriverSkipReason(string $flavor): ?string
    {
        if (! extension_loaded('fiberio')) {
            return 'fiberio extension not loaded: run with php -d extension=<path>/fiberio.so';
        }

        if (! extension_loaded('pdo_mysql')) {
            return 'pdo_mysql extension not loaded';
        }

        if (! ($flavor === 'mysql' ? mysqlAvailable() : mariadbAvailable())) {
            return "{$flavor} test server not reachable";
        }

        return null;
    }
}

if (! function_exists('nativeDriverConfig')) {
    /**
     * Connection config for the fledge-{flavor}-native driver; $extra wins.
     *
     * @return array<string, mixed>
     */
    function nativeDriverConfig(string $flavor, array $extra = []): array
    {
        return $extra + ($flavor === 'mysql' ? mysqlConfig() : mariadbConfig()) + [
            'driver' => "fledge-{$flavor}-native",
            'name' => "native-{$flavor}",
            'prefix' => '',
            'collation' => 'utf8mb4_unicode_ci',
            'pool_size' => 4,
            'pool_idle_timeout' => 60,
        ];
    }
}

if (! function_exists('nativeDriverConnection')) {
    /**
     * A native Laravel connection built through the real connector, or the
     * calling test is skipped when fiberio or the server is missing.
     */
    function nativeDriverConnection(string $flavor, array $extra = []): NativeMySqlConnection|NativeMariaDbConnection
    {
        if (($reason = nativeDriverSkipReason($flavor)) !== null) {
            test()->markTestSkipped($reason);
        }

        $config = nativeDriverConfig($flavor, $extra);

        $pool = ($flavor === 'mysql' ? new NativeMySqlConnector : new NativeMariaDbConnector)->connect($config);

        $connection = $flavor === 'mysql'
            ? new NativeMySqlConnection($pool, $config['database'], '', $config)
            : new NativeMariaDbConnection($pool, $config['database'], '', $config);

        try {
            $connection->scalar('SELECT 1');
        } catch (QueryException $e) {
            test()->markTestSkipped("{$flavor} connection failed: ".$e->getMessage());
        }

        return $connection;
    }
}

if (! function_exists('nativeDatabaseManager')) {
    /**
     * A DatabaseManager wired like an app: FiberDatabaseServiceProvider
     * registered on a container, one connection named "native" on the
     * fledge-{flavor}-native driver, so ->connection() is what DB::connection()
     * resolves. The calling test is skipped when fiberio or the server is missing.
     */
    function nativeDatabaseManager(string $flavor, array $extra = []): DatabaseManager
    {
        if (($reason = nativeDriverSkipReason($flavor)) !== null) {
            test()->markTestSkipped($reason);
        }

        $container = new Container;

        $container->instance('config', new class(['database' => ['default' => 'native', 'connections' => ['native' => nativeDriverConfig($flavor, $extra)]]]) implements ArrayAccess
        {
            public function __construct(private array $items) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return Arr::get($this->items, $key, $default);
            }

            public function offsetExists(mixed $offset): bool
            {
                return Arr::has($this->items, $offset);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return Arr::get($this->items, $offset);
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                Arr::set($this->items, $offset, $value);
            }

            public function offsetUnset(mixed $offset): void
            {
                Arr::forget($this->items, $offset);
            }
        });

        $container->singleton('db.transactions', fn () => new DatabaseTransactionsManager);

        (new FiberDatabaseServiceProvider($container))->register();

        return new DatabaseManager($container, new ConnectionFactory($container));
    }
}

if (! function_exists('nativeDriverReset')) {
    /**
     * Turn fiberio off again so later tests in the same process run on plain blocking PDO.
     */
    function nativeDriverReset(): void
    {
        if (function_exists('FiberIo\enabled') && \FiberIo\enabled()) {
            \FiberIo\disable();
        }
    }
}
