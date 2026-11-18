<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Future;
use Fledge\Async\Database\SqlCommonConnectionPool;
use Fledge\Async\Database\SqlConnector;
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
     * @param positive-int $maxConnections
     * @param positive-int $idleTimeout
     * @param bool $resetConnections True to automatically execute DISCARD ALL on a connection before use.
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

    #[\Override]
    protected function createStatementPool(string $sql, \Closure $prepare): PostgresStatement
    {
        return new Internal\PostgresStatementPool($this, $sql, $prepare);
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

        if ($this->resetConnections) {
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
        return parent::query($sql);
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
        return parent::execute($sql, $params);
    }

    /**
     * Changes return type to this library's Transaction type.
     */
    #[\Override]
    public function beginTransaction(): PostgresTransaction
    {
        return parent::beginTransaction();
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
        } finally {
            $this->push($connection);
        }

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
            $this->push($connection);
        }
    }

    #[\Override]
    public function quoteIdentifier(string $name): string
    {
        $connection = $this->pop();

        try {
            return $connection->quoteIdentifier($name);
        } finally {
            $this->push($connection);
        }
    }

    #[\Override]
    public function escapeByteA(string $data): string
    {
        $connection = $this->pop();

        try {
            return $connection->escapeByteA($data);
        } finally {
            $this->push($connection);
        }
    }
}
