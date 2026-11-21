<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlCommonConnectionPool;
use Fledge\Async\Database\SqlConnection;
use Fledge\Loop;

/**
 * One pooled connection pinned to a fiber (or the main context) by a FledgePdo.
 *
 * Pins live in a FiberLocal. The loop clears FiberLocal storage after every
 * callback, and a fiber that is destroyed takes its storage with it, so the pin
 * is destroyed when its fiber's scope ends: normally, on an exception, or when
 * the fiber is abandoned. Before that, FledgePdo hands a pin back early when
 * another caller waits for a connection and the pin is releasable: its owner is
 * between statements, outside a transaction, has no unread rows, no prepared
 * statement it has not executed yet, did not just run DML, and has run only
 * statements that leave the session as it was (see FledgePdo::leavesSessionUnchanged()).
 *
 * On release the statements prepared on the connection are closed and detached
 * (they re-prepare on their next execute()). A connection whose session is as
 * connect() left it goes straight back to the pool. Any other is reset by $reset
 * first, so the next fiber never inherits user variables, temporary tables,
 * locks, the current database or session settings, and no waiter gets the
 * connection before the reset finished. A connection is closed instead of
 * returned when its reset fails or is unavailable, or when it still has an open
 * transaction (the server rolls that back).
 *
 * @internal
 */
final class SessionPin
{
    /**
     * Whether the owner began a transaction on this connection that it has not
     * committed or rolled back yet.
     */
    public bool $inTransaction = false;

    /**
     * Whether the owner ran a statement that may have changed the session.
     */
    public bool $sessionChanged = false;

    /**
     * Whether the owner is inside a shim operation (between statements when false).
     */
    public bool $busy = true;

    /**
     * Statements prepared on this connection.
     *
     * @var \WeakMap<FledgePdoStatement, true>
     */
    public \WeakMap $statements;

    /**
     * Unbuffered statements whose rows are not all read yet.
     *
     * @var \WeakMap<FledgePdoStatement, true>
     */
    public \WeakMap $unread;

    /**
     * Statements prepared on this connection and not executed yet: the owner is between its
     * prepare() and execute(), so a hand-over would make it prepare again.
     *
     * @var \WeakMap<FledgePdoStatement, true>
     */
    public \WeakMap $pending;

    /**
     * Set by an INSERT / UPDATE / DELETE / REPLACE until the owner's next statement runs, so a
     * follow-up SELECT LAST_INSERT_ID(), ROW_COUNT() or SHOW WARNINGS reads the same session.
     */
    public bool $holdForNext = false;

    private bool $released = false;

    /**
     * @param  (\Closure(SqlConnection): bool)|null  $reset  Resets the session; false or a throw closes the connection.
     *                                                      Null returns the connection unchanged.
     */
    public function __construct(
        public readonly SqlCommonConnectionPool $pool,
        public readonly SqlConnection $connection,
        private readonly ?\Closure $reset = null,
    ) {
        $this->statements = new \WeakMap;
        $this->unread = new \WeakMap;
        $this->pending = new \WeakMap;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Whether the connection can go back to the pool now without a reset.
     */
    public function isReleasable(): bool
    {
        return ! $this->released
            && ! $this->busy
            && ! $this->inTransaction
            && ! $this->sessionChanged
            && ! $this->holdForNext
            && \count($this->unread) === 0
            && \count($this->pending) === 0;
    }

    /**
     * Hand the connection back (once): as it is when the session is unchanged, otherwise
     * reset or closed first.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        $pool = $this->pool;
        $connection = $this->connection;
        $reset = $this->reset;
        $close = $this->inTransaction;
        $clean = ! $close && ! $this->sessionChanged && \count($this->unread) === 0;

        try {
            foreach ($this->statements as $statement => $_) {
                $statement->detachFromConnection();
            }

            $this->statements = new \WeakMap;
            $this->unread = new \WeakMap;
            $this->pending = new \WeakMap;

            if ($clean || $pool->isClosed()) {
                // Nothing to reset (or nobody left to hand it to): no round trip.
                $pool->releaseConnection($connection);

                return;
            }

            // Resetting and closing wait on the socket, which a destructor must not do.
            Loop::queue(static function () use ($pool, $connection, $reset, $close): void {
                try {
                    if (! $close && $reset !== null && ! $connection->isClosed() && ! $pool->isClosed()) {
                        $close = ! $reset($connection);
                    }
                } catch (\Throwable) {
                    $close = true;
                }

                try {
                    if ($close && ! $connection->isClosed() && ! $pool->isClosed()) {
                        $connection->close();
                    }
                } catch (\Throwable) {
                    // Already gone: the server dropped the session either way.
                } finally {
                    $pool->releaseConnection($connection);
                }
            });
        } catch (\Throwable) {
            // Destructors may run during shutdown, after the loop is gone.
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
