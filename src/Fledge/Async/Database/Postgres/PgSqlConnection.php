<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Loop;

final class PgSqlConnection extends Internal\PostgresHandleConnection implements PostgresConnection
{
    /**
     * @throws \Error If pecl-ev is used as a loop extension.
     */
    #[\Override]
    public static function connect(PostgresConfig $config, ?Cancellation $cancellation = null): self
    {
        // @codeCoverageIgnoreStart
        /** @psalm-suppress UndefinedClass */
        if (Loop::getDriver()->getHandle() instanceof \EvLoop) {
            throw new \Error('ext-pgsql is not compatible with pecl-ev; use pecl-pq or a different loop extension');
        } // @codeCoverageIgnoreEnd

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

        /**
         * @psalm-suppress MissingClosureParamType $resource is a resource and cannot be inferred in this context.
         * @psalm-suppress UndefinedVariable $poll is defined below.
         */
        $callback = static function (string $callbackId, $resource) use (
            &$poll,
            &$await,
            $connection,
            $config,
            $deferred,
            $hash,
        ): void {
            switch ($result = \pg_connect_poll($connection)) {
                case \PGSQL_POLLING_READING:
                    // Wait for readability only: a connected socket is always writable, so
                    // leaving the writable watcher on would spin this callback.
                    Loop::disable($await);
                    Loop::enable($poll);
                    return;

                case \PGSQL_POLLING_WRITING:
                    Loop::disable($poll);
                    Loop::enable($await);
                    return;

                case \PGSQL_POLLING_FAILED:
                    $deferred->error(new SqlConnectionException(\pg_last_error($connection)));
                    break;

                case \PGSQL_POLLING_OK:
                    $deferred->complete(new self($connection, $resource, $hash, $config));
                    break;

                default:
                    $deferred->error(new SqlConnectionException('Unexpected connection status value: ' . $result));
                    break;
            }

            Loop::disable($poll);
            Loop::disable($await);
        };

        // libpq starts an async connect in the writing state, so wait for writability first
        // and switch watchers as pg_connect_poll() reports what it needs next.
        $poll = Loop::disable(Loop::onReadable($socket, $callback));
        $await = Loop::onWritable($socket, $callback);

        try {
            return $deferred->getFuture()->await($cancellation);
        } finally {
            Loop::cancel($poll);
            Loop::cancel($await);
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
