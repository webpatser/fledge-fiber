<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;

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
        } catch (\Throwable) {
            return null;
        }
    }
}
