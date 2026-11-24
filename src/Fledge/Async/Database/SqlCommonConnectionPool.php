<?php declare(strict_types=1);

namespace Fledge\Async\Database;

use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Future;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Database\SqlLink;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Async\Database\SqlTransactionIsolation;
use Fledge\Async\Database\SqlTransactionIsolationLevel;
use Fledge\Fiber\Database\PoolStats;
use Fledge\Loop;
use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * @template TConfig of SqlConfig
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 * @template TTransaction of SqlTransaction
 * @template TConnection of SqlConnection<TConfig, TResult, TStatement, TTransaction>
 *
 * @implements SqlConnectionPool<TConfig, TResult, TStatement, TTransaction>
 */
abstract class SqlCommonConnectionPool implements SqlConnectionPool, PoolStats
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_MAX_CONNECTIONS = 100;
    public const DEFAULT_IDLE_TIMEOUT = 60;

    /** @var \SplQueue<TConnection> */
    private readonly \SplQueue $idle;

    /** @var \SplObjectStorage<TConnection, null> */
    private readonly \SplObjectStorage $connections;

    /** @var Future<TConnection>|null */
    private ?Future $future = null;

    /** @var DeferredFuture<TConnection>|null */
    private ?DeferredFuture $awaitingConnection = null;

    /** Fledge: callers blocked in popConnection(), queued on $future or awaiting a connection. */
    private int $waiting = 0;

    private readonly DeferredFuture $onClose;

    /** @var (\Closure():bool)|null See setReclaimer(). */
    private ?\Closure $reclaimer = null;

    /** @var \WeakMap<TStatement, true> */
    private \WeakMap $statements;

    /**
     * Creates a Statement of the appropriate type using the Statement object returned by the Link object and the
     * given release callable.
     *
     * @param TStatement $statement
     * @param \Closure():void $release
     *
     * @return TStatement
     */
    abstract protected function createStatement(SqlStatement $statement, \Closure $release): SqlStatement;

    /**
     * Creates a Result of the appropriate type using the Result object returned by the Link object and the
     * given release callable.
     *
     * @param TResult $result
     * @param \Closure():void $release
     * @return TResult
     */
    abstract protected function createResult(SqlResult $result, \Closure $release): SqlResult;

    /**
     * @param \Closure(string):TStatement $prepare
     *
     * @return TStatement
     */
    abstract protected function createStatementPool(string $sql, \Closure $prepare): SqlStatement;

    /**
     * Creates a Transaction of the appropriate type using the Transaction object returned by the Link object and the
     * given release callable.
     *
     * @param TTransaction $transaction
     * @param \Closure():void $release
     *
     * @return TTransaction
     */
    abstract protected function createTransaction(SqlTransaction $transaction, \Closure $release): SqlTransaction;

    /**
     * @param TConfig $config
     * @param SqlConnector<TConfig, TConnection> $connector
     * @param positive-int $maxConnections Maximum number of active connections in the pool.
     * @param positive-int $idleTimeout Number of seconds until idle connections are removed from the pool.
     * @param float|null $waitTimeout Fledge: seconds a caller may wait for a connection (the wait on a full
     *     pool and the connect included) before pop() throws an SqlException; null waits as long as it takes.
     */
    public function __construct(
        private readonly SqlConfig $config,
        private readonly SqlConnector $connector,
        private readonly int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
        private int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        private SqlTransactionIsolation $transactionIsolation = SqlTransactionIsolationLevel::Committed,
        private readonly ?float $waitTimeout = null,
    ) {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->idleTimeout < 1) {
            throw new \Error("The idle timeout must be 1 or greater");
        }

        if ($this->waitTimeout !== null && $this->waitTimeout <= 0) {
            throw new \Error("The wait timeout must be greater than 0");
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->maxConnections < 1) {
            throw new \Error("Pool must contain at least one connection");
        }

        $this->connections = $connections = new \SplObjectStorage();

        /** @var \WeakMap<TStatement, true> For Psalm. */
        $this->statements = new \WeakMap();

        $this->idle = $idle = new \SplQueue();
        $this->onClose = new DeferredFuture();

        $idleTimeout = &$this->idleTimeout;

        $timeoutWatcher = Loop::repeat(1, static function () use (&$idleTimeout, $connections, $idle) {
            $now = \time();
            while (!$idle->isEmpty()) {
                $connection = $idle->bottom();
                \assert($connection instanceof SqlLink);

                if ($connection->getLastUsedAt() + $idleTimeout > $now) {
                    return;
                }

                // Close connection and remove it from the pool.
                $idle->shift();
                /** @psalm-suppress InvalidArgument SplObjectStorage::offsetUnset() expects an argument. */
                $connections->offsetUnset($connection);
                $connection->close();
            }
        });

        Loop::unreference($timeoutWatcher);
        $this->onClose(static fn () => Loop::cancel($timeoutWatcher));
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return $this->transactionIsolation;
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        $this->transactionIsolation = $isolation;
    }

    public function getConfig(): SqlConfig
    {
        return $this->config;
    }

    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    /**
     * Fledge: seconds a caller may wait for a connection, or null for no limit.
     */
    public function getWaitTimeout(): ?float
    {
        return $this->waitTimeout;
    }

    public function getLastUsedAt(): int
    {
        // Simple implementation... can be improved if needed.

        $time = 0;

        foreach ($this->connections as $connection) {
            \assert($connection instanceof SqlLink);
            if (($lastUsedAt = $connection->getLastUsedAt()) > $time) {
                $time = $lastUsedAt;
            }
        }

        return $time;
    }

    public function isClosed(): bool
    {
        return $this->onClose->isComplete();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose)->ignore();
    }

    /**
     * Close all connections in the pool. No further queries may be made after a pool is closed.
     */
    public function close(): void
    {
        if ($this->onClose->isComplete()) {
            return;
        }

        foreach ($this->connections as $connection) {
            // Avoid first class callable syntax to avoid psalm crash
            /** @psalm-suppress MissingClosureReturnType */
            async(fn () => $connection->close())->ignore();
        }

        foreach ($this->statements as $statement => $_) {
            $statement->close();
        }

        $this->onClose->complete();

        $this->awaitingConnection?->error(new SqlException("Connection pool closed"));
        $this->awaitingConnection = null;
    }

    /**
     * @return TConnection
     *
     * @throws SqlException
     */
    public function extractConnection(): SqlConnection
    {
        $connection = $this->pop();
        $this->connections->offsetUnset($connection);
        return $connection;
    }

    /**
     * Take a connection for exclusive use while it stays part of the pool: it counts against the
     * connection limit (callers wait for it like for any busy connection) and closes with the pool.
     * Hand it back with {@see releaseConnection()}.
     *
     * @return TConnection
     *
     * @throws SqlException
     */
    public function leaseConnection(): SqlConnection
    {
        return $this->pop();
    }

    /**
     * Return a connection taken with {@see leaseConnection()}. Once the pool is closed the connection
     * is dropped; a closed connection goes back like any other and pop() discards it (which also wakes
     * a caller waiting for a free slot, so it can open a replacement).
     */
    public function releaseConnection(SqlConnection $connection): void
    {
        if (!isset($this->connections[$connection])) {
            return;
        }

        if ($this->isClosed()) {
            $this->connections->offsetUnset($connection);

            return;
        }

        $this->push($connection);
    }

    /**
     * Fledge: called when a caller is about to wait because every connection is taken. It may
     * hand a leased connection back with {@see releaseConnection()} and return true, so the
     * caller looks again instead of waiting. Must not suspend.
     *
     * @param (\Closure():bool)|null $reclaimer
     */
    public function setReclaimer(?\Closure $reclaimer): void
    {
        $this->reclaimer = $reclaimer;
    }

    /**
     * Fledge: whether a caller is waiting for a connection to come back.
     */
    public function isAwaitingConnection(): bool
    {
        return $this->awaitingConnection !== null;
    }

    public function getConnectionCount(): int
    {
        return $this->connections->count();
    }

    public function getIdleConnectionCount(): int
    {
        return $this->idle->count();
    }

    /**
     * Fledge: PoolStats alias of {@see getConnectionCount()}.
     */
    public function count(): int
    {
        return $this->connections->count();
    }

    /**
     * Fledge: PoolStats alias of {@see getIdleConnectionCount()}.
     */
    public function getIdleCount(): int
    {
        return $this->idle->count();
    }

    /**
     * Fledge: PoolStats alias of {@see getConnectionLimit()}.
     */
    public function getMaxSize(): int
    {
        return $this->maxConnections;
    }

    /**
     * Fledge: callers blocked waiting for a connection, queued behind another caller or a connect included.
     */
    public function getWaitingCount(): int
    {
        return $this->waiting;
    }

    public function getConnectionLimit(): int
    {
        return $this->maxConnections;
    }

    /**
     * @return TConnection
     *
     * @throws SqlException If creating a new connection fails, or no connection came within the wait timeout.
     * @throws \Error If the pool has been closed.
     */
    protected function pop(): SqlConnection
    {
        if ($this->isClosed()) {
            throw new \Error("The pool has been closed");
        }

        if ($this->waitTimeout === null) {
            return $this->popConnection(null);
        }

        // Fledge: one deadline for the whole call, the wait for the turn and the connect included. The timer
        // stays referenced, as a caller waiting for a connection keeps the loop alive until it gets one.
        $deadline = new DeferredCancellation();
        $timer = Loop::delay($this->waitTimeout, static fn () => $deadline->cancel());

        try {
            return $this->popConnection($deadline->getCancellation());
        } finally {
            Loop::cancel($timer);
        }
    }

    /**
     * @return TConnection
     *
     * @throws SqlException If creating a new connection fails or the deadline passed.
     */
    private function popConnection(?Cancellation $deadline): SqlConnection
    {
        // Prevent simultaneous connection creation or waiting. Only the fiber that owns the future clears the
        // field, once it resumes; await() returns a completed future at once, so when the owner's resume is
        // still queued, yield until it ran (a timer runs after every queued resume). That also keeps the order:
        // the caller that waited first takes the connection before a newer one.
        while (($future = $this->future) !== null) {
            if ($future->isComplete()) {
                delay(0);
            }

            // Fledge: counted for getWaitingCount().
            $this->waiting++;

            try {
                $future->await($deadline);
            } catch (CancelledException $exception) {
                // A queued caller holds nothing, so on its deadline it just leaves.
                throw $this->timedOut($exception, $deadline);
            } finally {
                $this->waiting--;
            }
        }

        do {
            // While loop to ensure an idle connection is available after futures below are resolved.
            while ($this->idle->isEmpty()) {
                if ($this->connections->count() < $this->getConnectionLimit()) {
                    // Max connection count has not been reached, so open another connection.
                    try {
                        $this->future = $connect = async(fn () => $this->connector->connect($this->config));

                        try {
                            $connection = $connect->await($deadline);
                        } catch (CancelledException $exception) {
                            if ($deadline?->isRequested()) {
                                // Fledge: the connect goes on without a caller, so close what it opens. Callers
                                // queued on it wake when it settles, or leave on their own deadlines.
                                $connect->map(static fn (SqlConnection $late) => $late->close())->ignore();
                            }

                            throw $this->timedOut($exception, $deadline);
                        }

                        /** @psalm-suppress DocblockTypeContradiction */
                        if (!$connection instanceof SqlLink) {
                            throw new \Error(\sprintf(
                                "%s::connect() must resolve to an instance of %s",
                                \get_class($this->connector),
                                SqlLink::class
                            ));
                        }
                    } finally {
                        if ($this->future === $connect) {
                            $this->future = null;
                        }
                    }

                    if ($this->isClosed()) {
                        $connection->close();
                        break 2; // Break to throwing exception.
                    }

                    $this->connections->offsetSet($connection);
                    return $connection;
                }

                // Fledge: a holder of a leased connection it does not need right now may hand it back.
                if ($this->reclaimer !== null && ($this->reclaimer)()) {
                    continue;
                }

                // All possible connections busy, so wait until one becomes available.
                // Fledge: counted for getWaitingCount().
                $this->waiting++;

                try {
                    $this->awaitingConnection = $awaiting = new DeferredFuture;

                    $this->future = $future = $awaiting->getFuture();
                    // Connection will be pulled from $this->idle when future is resolved.
                    $future->await($deadline);
                } catch (CancelledException $exception) {
                    // Fledge: push() puts the connection into idle before it completes the future, so one that
                    // came back after the deadline fired but before this caller resumed is taken, not left.
                    if (!$deadline?->isRequested() || $this->idle->isEmpty()) {
                        throw $this->timedOut($exception, $deadline);
                    }
                } finally {
                    $this->waiting--;

                    if ($this->future === $future) {
                        $this->future = null;
                    }

                    // Fledge: a caller that gives up before push() hands the turn on. Completing its future
                    // without a connection wakes the callers queued on it, and the first one waits in its place.
                    if ($this->awaitingConnection === $awaiting) {
                        $this->awaitingConnection = null;
                        $awaiting->complete(null);
                    }
                }
            }

            $connection = $this->idle->dequeue();
            \assert($connection instanceof SqlLink);

            if (!$connection->isClosed()) {
                return $connection;
            }

            $this->connections->offsetUnset($connection);
        } while (!$this->isClosed());

        throw new SqlException("Pool closed before an active connection could be obtained");
    }

    /**
     * Fledge: the exception for a cancelled wait, the pool's timeout when $deadline fired.
     *
     * Code 2002 is mysqlnd's CR_CONNECTION_ERROR. The PDO shims report a plain SqlException as a general error
     * with its code, and the message matches none of Laravel's lost connection texts, so Laravel throws the
     * QueryException instead of reconnecting, which would build a second pool and bypass its limit.
     */
    private function timedOut(CancelledException $exception, ?Cancellation $deadline): \Throwable
    {
        if (!$deadline?->isRequested()) {
            return $exception;
        }

        return new SqlException(\sprintf(
            "Timed out after %ss waiting for a connection from the pool (%d of %d connections in use)",
            $this->waitTimeout,
            $this->connections->count() - $this->idle->count(),
            $this->getConnectionLimit(),
        ), 2002);
    }

    /**
     * @param TConnection $connection
     *
     * @throws \Error If the connection is not part of this pool.
     */
    protected function push(SqlConnection $connection): void
    {
        \assert(isset($this->connections[$connection]), 'Connection is not part of this pool');

        $this->idle->enqueue($connection);

        $this->awaitingConnection?->complete($connection);
        $this->awaitingConnection = null;
    }

    public function query(string $sql): SqlResult
    {
        $connection = $this->pop();

        try {
            $result = $connection->query($sql);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createResult($result, fn () => $this->push($connection));
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $connection = $this->pop();

        try {
            $result = $connection->execute($sql, $params);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createResult($result, fn () => $this->push($connection));
    }

    /**
     * Prepared statements returned by this method will stay alive as long as the pool remains open.
     */
    public function prepare(string $sql): SqlStatement
    {
        /** @psalm-suppress InvalidArgument Psalm is not properly detecting the templated return type. */
        $statement = $this->createStatementPool($sql, $this->prepareStatement(...));

        $this->statements[$statement] = true;

        /** @var TStatement $statement Psalm is not properly detecting the templated type. */
        return $statement;
    }

    /**
     * Prepares a new statement on an available connection.
     *
     * @return TStatement
     *
     * @throws SqlException
     */
    private function prepareStatement(string $sql): SqlStatement
    {
        $connection = $this->pop();

        try {
            $statement = $connection->prepare($sql);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createStatement($statement, fn () => $this->push($connection));
    }

    public function beginTransaction(): SqlTransaction
    {
        $connection = $this->pop();

        try {
            $connection->setTransactionIsolation($this->transactionIsolation);
            $transaction = $connection->beginTransaction();
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createTransaction($transaction, fn () => $this->push($connection));
    }
}
