<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Database\SqlTransaction;
use PDO;

/**
 * Abstract PDO-compatible shim wrapping an Fledge Async SQL connection pool.
 *
 * Implements the subset of PDO methods used by Illuminate\Database\Connection:
 * prepare, exec, lastInsertId, beginTransaction, commit, rollBack,
 * inTransaction, getAttribute, quote.
 *
 * Transaction pinning: Fledge Async pools dispatch queries to different connections.
 * beginTransaction() obtains a pinned SqlTransaction so all subsequent
 * queries within the transaction hit the same server connection.
 *
 * Query errors: SqlQueryError from the driver is rethrown as a PDO-shaped
 * FledgePdoException so Laravel's unique-constraint and concurrency detection work.
 */
abstract class FledgePdo
{
    /**
     * The Fledge Async connection pool.
     */
    protected SqlConnectionPool $pool;

    /**
     * The active transaction (pinned to a single connection), if any.
     */
    protected ?SqlTransaction $transaction = null;

    /**
     * The last insert ID from the most recent insert.
     */
    protected string|false $lastInsertId = false;

    /**
     * Cached server version string.
     */
    protected ?string $serverVersion = null;

    /**
     * Create a new Fledge Async PDO shim.
     */
    public function __construct(SqlConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Prepare a statement for execution.
     */
    public function prepare(string $query, array $options = []): FledgePdoStatement
    {
        $executor = $this->transaction ?? $this->pool;

        try {
            $statement = $executor->prepare($query);
        } catch (SqlQueryError $e) {
            throw $this->toPdoException($e);
        }

        return new FledgePdoStatement($statement, pdo: $this);
    }

    /**
     * Execute an SQL statement and return the number of affected rows.
     */
    public function exec(string $statement): int|false
    {
        $executor = $this->transaction ?? $this->pool;

        try {
            $result = $executor->query($statement);
        } catch (SqlQueryError $e) {
            throw $this->toPdoException($e);
        }

        $this->trackLastInsertId($result);

        return $result->getRowCount() ?? 0;
    }

    /**
     * Begin a transaction.
     *
     * Obtains a pinned connection from the pool so all subsequent
     * queries within the transaction use the same server connection.
     */
    public function beginTransaction(): bool
    {
        try {
            $this->transaction = $this->pool->beginTransaction();
        } catch (SqlQueryError $e) {
            throw $this->toPdoException($e);
        }

        return true;
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): bool
    {
        if ($this->transaction === null) {
            return false;
        }

        try {
            $this->transaction->commit();
        } catch (SqlQueryError $e) {
            throw $this->toPdoException($e);
        }
        $this->transaction = null;

        return true;
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): bool
    {
        if ($this->transaction === null) {
            return false;
        }

        try {
            $this->transaction->rollback();
        } catch (SqlQueryError $e) {
            throw $this->toPdoException($e);
        }
        $this->transaction = null;

        return true;
    }

    /**
     * Check if inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->transaction !== null;
    }

    /**
     * Returns the ID of the last inserted row.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertId;
    }

    /**
     * Retrieve a database connection attribute.
     */
    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_SERVER_VERSION) {
            return $this->getServerVersion();
        }

        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return $this->getDriverName();
        }

        return null;
    }

    /**
     * Translate a driver query error into the PDOException pdo_mysql/pdo_pgsql would throw.
     */
    public function toPdoException(SqlQueryError $error): FledgePdoException
    {
        return FledgePdoException::fromQueryError($error, $this->driverErrorCode($error));
    }

    /**
     * The native error code PDO reports for this driver (errorInfo[1]).
     */
    protected function driverErrorCode(SqlQueryError $error): int
    {
        return $error->getErrorCode();
    }

    /**
     * Get the server version string, querying on first call.
     */
    protected function getServerVersion(): string
    {
        if ($this->serverVersion === null) {
            $result = $this->pool->query($this->getVersionQuery());
            $row = $result->fetchRow();
            $this->serverVersion = $row ? (string) reset($row) : 'unknown';
        }

        return $this->serverVersion;
    }

    /**
     * Get the SQL query to retrieve the server version.
     */
    abstract protected function getVersionQuery(): string;

    /**
     * Get the PDO driver name.
     */
    abstract protected function getDriverName(): string;

    /**
     * Quote a string for use in a query.
     */
    abstract public function quote(string $string, int $type = PDO::PARAM_STR): string|false;

    /**
     * Track the last insert ID from a result.
     */
    abstract public function trackLastInsertId(mixed $result): void;

    /**
     * Close the connection pool.
     */
    public function close(): void
    {
        $this->pool->close();
    }
}
