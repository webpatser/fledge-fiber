<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlConnectionPool;
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
 * Errors: every public method runs through guard(), which turns anything the
 * async driver raises into the PDOException pdo_mysql/pdo_pgsql would throw, so
 * Laravel's unique-constraint, concurrency and lost-connection detection work.
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
        return $this->guard(fn () => new FledgePdoStatement(
            ($this->transaction ?? $this->pool)->prepare($query),
            pdo: $this,
            driver: $this->getDriverName(),
        ));
    }

    /**
     * Execute an SQL statement and return the number of affected rows.
     */
    public function exec(string $statement): int|false
    {
        return $this->guard(function () use ($statement): int {
            $result = ($this->transaction ?? $this->pool)->query($statement);

            $this->trackLastInsertId($result);

            return $result->getRowCount() ?? 0;
        });
    }

    /**
     * Begin a transaction.
     *
     * Obtains a pinned connection from the pool so all subsequent
     * queries within the transaction use the same server connection.
     */
    public function beginTransaction(): bool
    {
        return $this->guard(function (): bool {
            if ($this->transaction !== null) {
                throw FledgePdoException::pdoError('There is already an active transaction');
            }

            $this->transaction = $this->pool->beginTransaction();

            return true;
        });
    }

    /**
     * Commit the current transaction.
     *
     * The driver deactivates the transaction even when COMMIT fails (the server
     * rolls back on a deadlock or a lost connection), so the pin is always released.
     */
    public function commit(): bool
    {
        return $this->guard(function (): bool {
            $transaction = $this->transaction ?? throw FledgePdoException::pdoError('There is no active transaction');

            try {
                $transaction->commit();
            } finally {
                $this->transaction = null;
            }

            return true;
        });
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): bool
    {
        return $this->guard(function (): bool {
            $transaction = $this->transaction ?? throw FledgePdoException::pdoError('There is no active transaction');

            try {
                $transaction->rollback();
            } finally {
                $this->transaction = null;
            }

            return true;
        });
    }

    /**
     * Check if inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->guard(fn (): bool => $this->transaction !== null);
    }

    /**
     * Returns the ID of the last inserted row.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->guard(fn (): string|false => $this->lastInsertId);
    }

    /**
     * Retrieve a database connection attribute.
     */
    public function getAttribute(int $attribute): mixed
    {
        return $this->guard(fn (): mixed => match ($attribute) {
            PDO::ATTR_SERVER_VERSION => $this->getServerVersion(),
            PDO::ATTR_DRIVER_NAME => $this->getDriverName(),
            default => null,
        });
    }

    /**
     * Run a shim operation, translating anything the async driver raises into the
     * PDOException pdo_mysql/pdo_pgsql would throw. Every public method goes through here.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    public function guard(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            throw $this->toPdoException($e);
        }
    }

    /**
     * Translate a driver throwable into the PDOException pdo_mysql/pdo_pgsql would throw.
     * Programming errors (TypeError and the like) come back untouched.
     */
    public function toPdoException(\Throwable $error): \Throwable
    {
        return FledgePdoException::fromThrowable($error, $this->getDriverName());
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
        $this->guard(fn () => $this->pool->close());
    }
}
