<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Database\SqlConnectionException;
use pq;
use Fledge\Loop;

final class PqConnection extends Internal\PostgresHandleConnection implements PostgresConnection
{
    private readonly Internal\PqHandle $handle;

    #[\Override]
    public static function connect(PostgresConfig $config, ?Cancellation $cancellation = null): self
    {
        try {
            $connection = new pq\Connection($config->getConnectionString(), pq\Connection::ASYNC);
        } catch (pq\Exception $exception) {
            throw new SqlConnectionException("Could not connect to PostgreSQL server", 0, $exception);
        }

        // Fledge: nonblocking and unbuffered are set once connected. PQsetnonblocking() clears libpq's
        // error buffer (libpq 15+), which during the connect drops the "connection to server at ...
        // failed: " prefix libpq already wrote, so a failed connect would lose the text pdo_pgsql shows.
        // The connect itself is non-blocking anyway (PQconnectStart).
        $deferred = new DeferredFuture();
        $callbackId = null;

        // Fledge: libpq may replace its socket during the connect (next address or host after a failed
        // attempt, or a retry without SSL), and it closes the old one inside poll(). A kqueue or epoll
        // registration made for the old descriptor never fires for the new one. pq creates its socket
        // stream once, in the constructor, so a fresh stream as PgSqlConnection uses is not available:
        // every step cancels its watcher before polling and watches the stream again from a deferred
        // callback, after the loop has dropped the old registration, so the driver registers the
        // descriptor anew. This relies on the new socket reusing the old descriptor number (the lowest
        // free one), as pq's socket property itself does.
        $watch = static function (int $result) use (&$step, &$callbackId, $connection): void {
            $callbackId = $result === pq\Connection::POLLING_READING
                ? Loop::onReadable($connection->socket, $step)
                : Loop::onWritable($connection->socket, $step);
        };

        $step = static function () use (&$callbackId, $watch, $connection, $config, $deferred): void {
            Loop::cancel($callbackId);
            $callbackId = null;

            switch ($result = $connection->poll()) {
                case pq\Connection::POLLING_READING:
                case pq\Connection::POLLING_WRITING:
                    $callbackId = Loop::defer(static function () use ($watch, $result): void {
                        $watch($result);
                    });
                    return;

                case pq\Connection::POLLING_FAILED:
                    $deferred->error(new SqlConnectionException($connection->errorMessage));
                    return;

                case pq\Connection::POLLING_OK:
                    $connection->nonblocking = true;
                    $connection->unbuffered = true;

                    $deferred->complete(new self($connection, $config));
                    return;

                default:
                    $deferred->error(new SqlConnectionException('Unexpected connection status value: ' . $result));
                    return;
            }
        };

        // libpq starts an async connect in the writing state.
        $watch(pq\Connection::POLLING_WRITING);

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

    protected function __construct(pq\Connection $handle, PostgresConfig $config)
    {
        $this->handle = new Internal\PqHandle($handle, $config);
        parent::__construct($this->handle);
    }

    /**
     * @return bool True if result sets are buffered in memory, false if unbuffered.
     */
    public function isBufferingResults(): bool
    {
        return $this->handle->isBufferingResults();
    }

    /**
     * Sets result sets to be fully buffered in local memory.
     */
    public function shouldBufferResults(): void
    {
        $this->handle->shouldBufferResults();
    }

    /**
     * Sets result sets to be streamed from the database server.
     */
    public function shouldNotBufferResults(): void
    {
        $this->handle->shouldNotBufferResults();
    }
}
