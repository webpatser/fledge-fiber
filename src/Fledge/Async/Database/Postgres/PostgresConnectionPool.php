<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Future;
use Fledge\Async\Database\SqlCommonConnectionPool;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Async\Database\SqlTransactionIsolation;
use Fledge\Async\Database\SqlTransactionIsolationLevel;
use function Fledge\Async\async;

/**
 * @extends SqlCommonConnectionPool<PostgresConfig, PostgresResult, PostgresStatement, PostgresTransaction, PostgresConnection>
 */
final class PostgresConnectionPool extends SqlCommonConnectionPool implements PostgresConnection
{
    /** @var Future<PostgresConnection>|null Connection used for notification listening. */
    private Future|null $listeningConnection = null;

    /** The resolved listening connection, once $listeningConnection completed. */
    private ?PostgresConnection $listeningLink = null;

    /** @var int Number of listeners on listening connection. */
    private int $listenerCount = 0;

    /**
     * Idle connections whose session is known to be unchanged since their last reset. Only the
     * paths that see every statement they run (query, execute, prepare, a pooled transaction,
     * notify, the quote helpers) hand a connection back through release() as clean; every
     * other path (listen, leaseConnection, a failed statement) uses push() and leaves it dirty.
     *
     * @var \WeakMap<PostgresConnection, true>
     */
    private \WeakMap $clean;

    /**
     * @param positive-int $maxConnections
     * @param positive-int $idleTimeout
     * @param bool $resetConnections True to execute DISCARD ALL on checkout of a connection whose session
     *     may have changed (fresh connections included); see Internal\PostgresSessionClassifier.
     * @param SqlConnector<PostgresConfig, PostgresConnection>|null $connector
     */
    public function __construct(
        PostgresConfig $config,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        private readonly bool $resetConnections = true,
        ?SqlConnector $connector = null,
        SqlTransactionIsolation $transactionIsolation = SqlTransactionIsolationLevel::Committed,
    ) {
        $this->clean = new \WeakMap();

        parent::__construct(
            config: $config,
            connector: $connector ?? postgresConnector(),
            maxConnections: $maxConnections,
            idleTimeout: $idleTimeout,
            transactionIsolation: $transactionIsolation,
        );
    }

    /**
     * @param \Closure():void $release
     */
    #[\Override]
    protected function createStatement(SqlStatement $statement, \Closure $release): PostgresStatement
    {
        \assert($statement instanceof PostgresStatement);
        return new Internal\PostgresPooledStatement($statement, $release);
    }

    #[\Override]
    protected function createResult(SqlResult $result, \Closure $release): PostgresResult
    {
        \assert($result instanceof PostgresResult);
        return new Internal\PostgresPooledResult($result, $release);
    }

    /**
     * The parent's $prepare hands the connection back without looking at $sql, so statements
     * are prepared through prepareTracked() instead.
     */
    #[\Override]
    protected function createStatementPool(string $sql, \Closure $prepare): PostgresStatement
    {
        return new Internal\PostgresStatementPool($this, $sql, $this->prepareTracked(...));
    }

    private function prepareTracked(string $sql): PostgresStatement
    {
        $clean = Internal\PostgresSessionClassifier::leavesSessionUnchanged($sql);
        $connection = $this->pop();

        try {
            $statement = $connection->prepare($sql);
        } catch (\Throwable $exception) {
            $this->releaseFailed($connection, $exception);
            throw $exception;
        }

        return $this->createStatement($statement, fn () => $this->release($connection, $clean));
    }

    /**
     * Hand back a connection whose statement failed (dirty). A lost connection is closed first,
     * as the failed DISCARD ALL in pop() does: when a clean session skips that reset, the
     * statement itself is the first to meet a session the server ended while idle, and a dead
     * handle left open keeps its closed socket on the loop for every other connection. Query
     * errors the server reported (SqlQueryError) and result parse errors keep the connection.
     */
    private function releaseFailed(PostgresConnection $connection, \Throwable $exception): void
    {
        if ($exception instanceof SqlException && !$exception instanceof PostgresParseException) {
            $connection->close();
        }

        $this->push($connection);
    }

    /**
     * Hand a connection back; $clean marks its session as unchanged, so the next checkout
     * skips DISCARD ALL.
     */
    private function release(PostgresConnection $connection, bool $clean): void
    {
        if ($clean) {
            $this->clean[$connection] = true;
        }

        $this->push($connection);
    }

    #[\Override]
    protected function createTransaction(SqlTransaction $transaction, \Closure $release): PostgresTransaction
    {
        \assert($transaction instanceof PostgresTransaction);
        return new Internal\PostgresPooledTransaction($transaction, $release);
    }

    #[\Override]
    protected function pop(): PostgresConnection
    {
        $connection = parent::pop();

        // Dirty from here on until the caller hands it back through release() as clean.
        $clean = isset($this->clean[$connection]);
        unset($this->clean[$connection]);

        if ($this->resetConnections && !$clean) {
            try {
                $connection->query("DISCARD ALL");
            } catch (\Throwable $exception) {
                // Session state is unknown: drop the connection and free its slot (the pool
                // discards closed connections), or the pool slowly runs out of connections.
                $connection->close();
                $this->push($connection);
                throw $exception;
            }
        }

        return $connection;
    }

    /**
     * Changes return type to this library's Result type.
     */
    #[\Override]
    public function query(string $sql): PostgresResult
    {
        $clean = Internal\PostgresSessionClassifier::leavesSessionUnchanged($sql);
        $connection = $this->pop();

        try {
            $result = $connection->query($sql);
        } catch (\Throwable $exception) {
            $this->releaseFailed($connection, $exception);
            throw $exception;
        }

        return $this->createResult($result, fn () => $this->release($connection, $clean));
    }

    /**
     * Changes return type to this library's Statement type.
     */
    #[\Override]
    public function prepare(string $sql): PostgresStatement
    {
        return parent::prepare($sql);
    }

    /**
     * Changes return type to this library's Result type.
     */
    #[\Override]
    public function execute(string $sql, array $params = []): PostgresResult
    {
        $clean = Internal\PostgresSessionClassifier::leavesSessionUnchanged($sql);
        $connection = $this->pop();

        try {
            $result = $connection->execute($sql, $params);
        } catch (\Throwable $exception) {
            $this->releaseFailed($connection, $exception);
            throw $exception;
        }

        return $this->createResult($result, fn () => $this->release($connection, $clean));
    }

    /**
     * Changes return type to this library's Transaction type. The connection comes back clean
     * only when the transaction ended through commit() or rollback() and ran nothing that
     * outlives it (see Internal\PostgresSessionTracker); an abandoned one is reset.
     */
    #[\Override]
    public function beginTransaction(): PostgresTransaction
    {
        $connection = $this->pop();
        $tracker = new Internal\PostgresSessionTracker();

        try {
            $connection->setTransactionIsolation($this->getTransactionIsolation());
            $transaction = $connection->beginTransaction();
        } catch (\Throwable $exception) {
            $this->releaseFailed($connection, $exception);
            throw $exception;
        }

        return new Internal\PostgresPooledTransaction(
            $transaction,
            fn () => $this->release($connection, $tracker->clean),
            $tracker,
        );
    }

    /**
     * Changes return type to this library's configuration type.
     */
    #[\Override]
    public function getConfig(): PostgresConfig
    {
        return parent::getConfig();
    }

    #[\Override]
    public function notify(string $channel, string $payload = ""): PostgresResult
    {
        $connection = $this->pop();

        try {
            $result = $connection->notify($channel, $payload);
        } catch (\Throwable $exception) {
            $this->releaseFailed($connection, $exception);
            throw $exception;
        }

        // NOTIFY outside a transaction leaves nothing in the session.
        $this->release($connection, true);

        return $result;
    }

    #[\Override]
    public function listen(string $channel): PostgresListener
    {
        // The listening session died (server ended it) while listeners still hold it: they
        // fail with the connection error, and new listeners get a fresh connection.
        if ($this->listeningLink?->isClosed()) {
            $this->resetListeningConnection();
        }

        $future = $this->listeningConnection ??= async($this->pop(...));

        // Count before awaiting, so a listener released meanwhile cannot hand the
        // connection back to the pool while this one is still attaching.
        ++$this->listenerCount;

        try {
            $connection = $future->await();
        } catch (\Throwable $exception) {
            // Every listener awaiting this future fails with it; the first one resets the state.
            if ($this->listeningConnection === $future) {
                $this->listeningConnection = null;
                $this->listenerCount = 0;
            }
            throw $exception;
        }

        $this->listeningLink = $connection;

        try {
            $listener = $connection->listen($channel);
        } catch (\Throwable $exception) {
            $this->releaseListeningConnection($future);
            throw $exception;
        }

        return new Internal\PostgresPooledListener(
            $listener,
            fn () => $this->releaseListeningConnection($future),
        );
    }

    /**
     * @param Future<PostgresConnection> $future The listening connection the listener was attached to.
     */
    private function releaseListeningConnection(Future $future): void
    {
        if ($this->listeningConnection !== $future) {
            return; // Already reset; the reset returned that connection to the pool.
        }

        if (--$this->listenerCount === 0 || $this->listeningLink?->isClosed()) {
            $this->resetListeningConnection();
        }
    }

    /**
     * Returns the listening connection to the pool (which drops it when closed) and lets
     * the next listener check out a connection of its own.
     */
    private function resetListeningConnection(): void
    {
        $connection = $this->listeningLink;

        $this->listeningConnection = null;
        $this->listeningLink = null;
        $this->listenerCount = 0;

        if ($connection !== null) {
            $this->push($connection);
        }
    }

    #[\Override]
    public function quoteLiteral(string $data): string
    {
        $connection = $this->pop();

        try {
            return $connection->quoteLiteral($data);
        } finally {
            $this->release($connection, true); // Escaping runs in the client, no statement.
        }
    }

    #[\Override]
    public function quoteIdentifier(string $name): string
    {
        $connection = $this->pop();

        try {
            return $connection->quoteIdentifier($name);
        } finally {
            $this->release($connection, true); // Escaping runs in the client, no statement.
        }
    }

    #[\Override]
    public function escapeByteA(string $data): string
    {
        $connection = $this->pop();

        try {
            return $connection->escapeByteA($data);
        } finally {
            $this->release($connection, true); // Escaping runs in the client, no statement.
        }
    }
}
