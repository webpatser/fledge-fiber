<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Loop;

final class PgSqlConnection extends Internal\PostgresHandleConnection implements PostgresConnection
{
    #[\Override]
    public static function connect(PostgresConfig $config, ?Cancellation $cancellation = null): self
    {
        if (!$connection = \pg_connect($config->getConnectionString(), \PGSQL_CONNECT_ASYNC | \PGSQL_CONNECT_FORCE_NEW)) {
            throw new SqlConnectionException("Failed to create connection resource");
        }

        if (\pg_connection_status($connection) === \PGSQL_CONNECTION_BAD) {
            throw new SqlConnectionException(\pg_last_error($connection));
        }

        if (!$socket = \pg_socket($connection)) {
            throw new SqlConnectionException("Failed to access connection socket");
        }

        // Type OIDs are per database, so the cached type table is too.
        $hash = \sha1(\serialize([$config->getHost(), $config->getPort(), $config->getUser(), $config->getDatabase()]));

        $deferred = new DeferredFuture();
        $callbackId = null;

        // Fledge: libpq may replace its socket during an asynchronous connect (it moves to the next
        // address or host after a failed attempt, or retries without SSL), and it does so inside
        // pg_connect_poll(), closing the old socket there. A kqueue or epoll registration made for the
        // old descriptor never fires for the new one, so a watcher kept across a poll step can wait
        // forever. Every step therefore cancels its watcher before polling, then watches a fresh
        // pg_socket() stream (a new stream resource, registered with libpq's current descriptor)
        // with the interest that step asked for.
        $step = static function () use (&$step, &$callbackId, $connection, $config, $deferred, $hash): void {
            Loop::cancel($callbackId);
            $callbackId = null;

            $result = \pg_connect_poll($connection);

            if ($result === \PGSQL_POLLING_READING || $result === \PGSQL_POLLING_WRITING) {
                if (!$socket = \pg_socket($connection)) {
                    $deferred->error(new SqlConnectionException("Failed to access connection socket"));
                    return;
                }

                // Readability only while reading: a connected socket is always writable, so a
                // writable watcher left on would spin this callback.
                $callbackId = $result === \PGSQL_POLLING_READING
                    ? Loop::onReadable($socket, $step)
                    : Loop::onWritable($socket, $step);

                return;
            }

            if ($result === \PGSQL_POLLING_OK) {
                if (!$socket = \pg_socket($connection)) {
                    $deferred->error(new SqlConnectionException("Failed to access connection socket"));
                    return;
                }

                $deferred->complete(new self($connection, $socket, $hash, $config));
                return;
            }

            $deferred->error(new SqlConnectionException($result === \PGSQL_POLLING_FAILED
                ? \pg_last_error($connection)
                : 'Unexpected connection status value: ' . $result));
        };

        // libpq starts an async connect in the writing state, so wait for writability first.
        $callbackId = Loop::onWritable($socket, $step);
        unset($socket);

        try {
            return $deferred->getFuture()->await($cancellation);
        } finally {
            if ($callbackId !== null) {
                Loop::cancel($callbackId);
            }

            // The step closure refers to itself; break that cycle so the connection is freed (and
            // its session ended) as soon as its handle lets go, not at the next GC run.
            $step = null;
        }
    }

    /**
     * @param \PgSql\Connection $handle PostgreSQL connection handle.
     * @param resource $socket PostgreSQL connection stream socket.
     * @param string $id Connection identifier for determining which cached type table to use.
     */
    protected function __construct(\PgSql\Connection $handle, $socket, string $id, PostgresConfig $config)
    {
        parent::__construct(new Internal\PgSqlHandle($handle, $socket, $id, $config));
    }
}
