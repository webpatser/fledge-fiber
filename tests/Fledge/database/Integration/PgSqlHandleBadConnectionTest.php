<?php

use Fledge\Async\Database\Postgres\Internal\PgSqlHandle;
use Fledge\Async\Database\Postgres\Internal\PostgresHandleConnection;
use Fledge\Async\Database\Postgres\PgSqlConnection;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\SqlConnectionException;

/*
 * A query can succeed and the connection go bad right after (the server ends the session
 * once the result is sent). The handle must then report closed, so the pool drops it
 * instead of handing the dead connection to the next query.
 */
beforeEach(function () {
    if (! extension_loaded('pgsql') || ! postgresAvailable()) {
        $this->markTestSkipped('ext-pgsql and the Postgres test server are required');
    }
});

it('closes a handle whose connection went bad after a successful query', function () {
    $c = postgresConfig();
    $config = new PostgresConfig($c['host'], $c['port'], $c['username'], $c['password'], $c['database']);
    $connection = PgSqlConnection::connect($config);

    $handle = (new ReflectionProperty(PostgresHandleConnection::class, 'handle'))->getValue($connection);
    expect($handle)->toBeInstanceOf(PgSqlHandle::class);

    $pid = (int) $connection->query('SELECT pg_backend_pid() AS pid')->fetchRow()['pid'];

    $admin = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
    $admin->query("SELECT pg_terminate_backend({$pid})");
    usleep(200_000);

    // Let libpq read the FATAL and EOF synchronously, without the event loop, so the
    // connection is BAD while the handle still holds it, as after a successful result.
    $raw = (new ReflectionProperty(PgSqlHandle::class, 'handle'))->getValue($handle);
    for ($i = 0; $i < 20 && pg_connection_status($raw) !== PGSQL_CONNECTION_BAD; $i++) {
        @pg_consume_input($raw);
        usleep(20_000);
    }
    expect(pg_connection_status($raw))->toBe(PGSQL_CONNECTION_BAD)
        ->and($handle->isClosed())->toBeFalse();

    (new ReflectionMethod(PgSqlHandle::class, 'closeIfConnectionBad'))->invoke($handle);

    expect($handle->isClosed())->toBeTrue()
        ->and($connection->isClosed())->toBeTrue()
        ->and(fn () => $connection->query('SELECT 1'))->toThrow(SqlConnectionException::class, 'server closed the connection unexpectedly');
});
