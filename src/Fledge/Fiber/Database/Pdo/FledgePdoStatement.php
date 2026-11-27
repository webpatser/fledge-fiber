<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\Mysql\Internal\MysqlConnectionStatement;
use Fledge\Async\Database\Mysql\Internal\MysqlPooledStatement;
use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Postgres\PostgresByteA;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use PDO;

/**
 * PDOStatement-compatible shim wrapping an Fledge Async SQL statement/result.
 *
 * Implements the subset of PDOStatement methods used by Illuminate\Database\Connection:
 * setFetchMode, execute, fetchAll, fetch, bindValue, rowCount, nextRowset, closeCursor.
 * Every public method runs through guard(), so no driver error leaks past it.
 */
class FledgePdoStatement
{
    /**
     * The Fledge Async prepared statement.
     */
    protected ?SqlStatement $statement;

    /**
     * The result from the last execute().
     */
    protected ?SqlResult $result = null;

    /**
     * The parent PDO shim, used to propagate lastInsertId after execute.
     */
    protected ?FledgePdo $pdo;

    /**
     * Bound parameter values indexed by position (1-based) or name.
     */
    protected array $bindings = [];

    /**
     * Whether a previous execute() may have left bound data on the driver statement.
     */
    protected bool $executed = false;

    /**
     * The current fetch mode.
     */
    protected int $fetchMode = PDO::FETCH_OBJ;

    /**
     * Additional fetch mode arguments (class name, ctor args, etc.).
     */
    protected array $fetchModeArgs = [];

    /**
     * The PDO driver name ("mysql" or "pgsql") errors are shaped for when no parent PDO is set.
     */
    protected ?string $driver;

    /**
     * The pinned transaction this statement was prepared in, if any.
     */
    protected ?SqlTransaction $transaction;

    /**
     * Whether execute() reads every result set into memory (pdo_mysql's default buffered
     * queries), freeing a pinned connection for the fiber's next statement.
     */
    protected bool $buffered;

    /**
     * The unread rows of the current result set when buffered (read ones are unset).
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $rows = null;

    /**
     * Position of the next buffered row.
     */
    protected int $rowIndex = 0;

    /**
     * Row count of the current buffered result set.
     */
    protected int $bufferedRowCount = 0;

    /**
     * The buffered result sets after the current one: row count and rows.
     *
     * @var list<array{int, list<array<string, mixed>>}>
     */
    protected array $pendingRowsets = [];

    /**
     * Set when the driver statement was dropped (its transaction ended, or its pinned connection
     * was released); the next execute() prepares $sql again on the current fiber's connection.
     */
    protected bool $detached = false;

    /**
     * The SQL, kept to re-prepare a detached statement.
     */
    protected ?string $sql;

    /**
     * The pin an unbuffered result with unread rows holds (weak: the pin belongs to its fiber).
     *
     * @var \WeakReference<SessionPin>|null
     */
    protected ?\WeakReference $cursorPin = null;

    /**
     * Rows taken from the driver result since the last execute().
     */
    protected int $driverRowsRead = 0;

    /**
     * Create a new Fledge Async PDO statement shim.
     */
    public function __construct(?SqlStatement $statement = null, ?SqlResult $result = null, ?FledgePdo $pdo = null, ?string $driver = null, ?SqlTransaction $transaction = null, bool $buffered = false, ?string $sql = null)
    {
        $this->statement = $statement;
        $this->result = $result;
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->transaction = $transaction;
        $this->buffered = $buffered;
        $this->sql = $sql;
    }

    /**
     * Set the default fetch mode for this statement.
     */
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        return $this->guard(function () use ($mode, $args): bool {
            $this->fetchMode = $mode;
            $this->fetchModeArgs = $args;

            return true;
        });
    }

    /**
     * Bind a value to a parameter.
     */
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return $this->guard(function () use ($param, $value, $type): bool {
            if ($type === PDO::PARAM_INT) {
                $value = (int) $value;
            } elseif ($type === PDO::PARAM_BOOL) {
                $value = (bool) $value;
            } elseif ($type === PDO::PARAM_NULL) {
                $value = null;
            } elseif ($type === PDO::PARAM_LOB && $value !== null) {
                $value = $this->lobValue($value);
            } elseif ($type === PDO::PARAM_STR && $value !== null) {
                $value = (string) $value;
            }

            $this->bindings[$param] = $value;

            return true;
        });
    }

    /**
     * Execute the prepared statement.
     *
     * Fledge Async uses positional parameters (0-based array). Laravel binds with
     * 1-based integer keys from bindValues(). We convert accordingly.
     */
    public function execute(?array $params = null): bool
    {
        // A statement prepared inside a pinned transaction runs on that transaction's
        // connection, so another fiber must not execute it while the transaction is open.
        if ($this->transaction !== null && ! $this->detached) {
            $this->pdo?->assertOwnsTransaction($this->transaction);
        }

        if ($this->detached) {
            $this->pdo?->assertOwnsTransaction();
        }

        return $this->guard(function () use ($params): bool {
            // Like pdo_mysql, executing again first discards this statement's own unread rows;
            // another statement's unread rows on the connection refuse the execute (error 2014).
            $this->discardUnreadRows();
            $this->pdo?->assertNoUnreadResult();

            if ($this->detached) {
                // pdo_mysql keeps a statement usable across transactions: prepare it again
                // on the current fiber's connection (or its open transaction).
                if ($this->pdo === null || $this->sql === null) {
                    throw FledgePdoException::pdoError('The connection this statement was prepared on is gone');
                }

                $this->pdo->reattach($this, $this->sql);
            }

            // Like PDO, bound values stay for the next execute(), and an array replaces all of them.
            if ($params !== null) {
                $this->bindings = [];

                foreach ($params as $key => $value) {
                    $this->bindings[is_int($key) ? $key + 1 : $key] = $value;
                }
            }

            $executeParams = $params ?? $this->buildExecuteParams();

            // Workaround for Fledge Async MySQL sending string params as LONG_BLOB type,
            // which breaks MariaDB native UUID columns. Pre-binding via bind()
            // sends the data as VarString instead (see Fledge Async MySQL#142).
            if ($this->statement instanceof MysqlStatement) {
                // Pre-binds append on the driver statement, so start the next execute from nothing.
                if ($this->executed) {
                    $this->result = null;
                    $this->resetPrebinds($this->statement);
                }

                $this->executed = true;
                $this->prebindUuids($executeParams);
            }

            if ($this->statement !== null) {
                // Drop the previous result before executing again: PHP evaluates the
                // right-hand side before releasing the old value, so the stale result
                // would still pin a pooled connection while execute() waits for one.
                $this->result = null;
                $this->clearBuffer();
                $this->driverRowsRead = 0;
                $this->pdo?->statementExecuting($this, $this->sql);
                $this->result = $this->statement->execute($executeParams);

                $this->pdo?->trackLastInsertId($this->result);

                if ($this->buffered) {
                    $this->bufferResults($this->result);
                } elseif ($this->sql !== null && $this->pdo !== null && $this->hasRows($this->result)) {
                    // Unbuffered on a pinned connection: the rows stay on the socket and keep the pin.
                    $this->cursorPin = $this->pdo->openCursor($this);
                }
            }

            return true;
        });
    }

    /**
     * Release the current result set, freeing its pooled connection.
     */
    public function closeCursor(): bool
    {
        return $this->guard(function (): bool {
            $this->discardUnreadRows();
            $this->result = null;
            $this->clearBuffer();

            return true;
        });
    }

    /**
     * Rows taken from the driver result since the last execute(): all of them at execute()
     * when buffered, one per fetch() when not.
     *
     * @internal
     */
    public function driverRowsRead(): int
    {
        return $this->driverRowsRead;
    }

    private function hasRows(SqlResult $result): bool
    {
        return $result instanceof MysqlResult && $result->getColumnDefinitions() !== null;
    }

    /**
     * Read and drop what is left of an unbuffered result, as mysqlnd does when a result is freed,
     * so the connection is free for the next statement.
     */
    private function discardUnreadRows(): void
    {
        if ($this->cursorPin === null) {
            return;
        }

        try {
            while ($this->result?->fetchRow() !== null) {
                // discard
            }
        } finally {
            $this->closeUnbufferedCursor();
        }
    }

    /**
     * The unbuffered result has no unread rows left: give up its hold on the pin.
     */
    private function closeUnbufferedCursor(): void
    {
        $pin = $this->cursorPin?->get();
        $this->cursorPin = null;

        if ($pin !== null) {
            unset($pin->unread[$this]);
        }
    }

    /**
     * Read every row of every result set, so nothing is left unread on the connection.
     */
    private function bufferResults(SqlResult $result): void
    {
        $this->rows = iterator_to_array($result, false);
        $this->bufferedRowCount = $result->getRowCount() ?? 0;
        $this->driverRowsRead = count($this->rows);

        for ($next = $result->getNextResult(); $next !== null; $next = $next->getNextResult()) {
            $this->pendingRowsets[] = [$next->getRowCount() ?? 0, $rows = iterator_to_array($next, false)];
            $this->driverRowsRead += count($rows);
        }

        // Keep no driver result: inside a transaction each one holds the connection busy.
        $this->result = null;
    }

    private function clearBuffer(): void
    {
        $this->rows = null;
        $this->rowIndex = 0;
        $this->bufferedRowCount = 0;
        $this->pendingRowsets = [];
    }

    /**
     * Close and drop the driver statement: its transaction ended (the connection stays busy
     * until every statement of the transaction is gone, and a pinned connection is the one
     * the fiber's next transaction needs), or its pinned connection is being released and
     * reset (which frees every server-side statement id). Buffered rows stay readable; the
     * next execute() prepares the SQL again.
     *
     * @internal
     */
    public function detachFromConnection(): void
    {
        $statement = $this->statement;
        $this->statement = null;
        $this->result = null;
        $this->detached = true;
        $this->closeUnbufferedCursor();

        try {
            $statement?->close();
        } catch (\Throwable) {
            // The connection is already gone.
        }
    }

    /**
     * Take a freshly prepared driver statement for a detached statement.
     *
     * @internal
     */
    public function attach(SqlStatement $statement, ?SqlTransaction $transaction): void
    {
        $this->guard(function () use ($statement, $transaction): void {
            $this->statement = $statement;
            $this->transaction = $transaction;
            $this->executed = false;
            $this->detached = false;
        });
    }

    /**
     * Run a statement operation through the parent PDO's guard, so driver errors
     * leave as the PDOException pdo_mysql/pdo_pgsql would throw.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    protected function guard(\Closure $operation): mixed
    {
        if ($this->pdo !== null) {
            return $this->pdo->guard($operation);
        }

        // A detached statement (no parent PDO, no driver given) has no driver to shape errors for.
        if ($this->driver === null) {
            return $operation();
        }

        try {
            return $operation();
        } catch (\Throwable $e) {
            throw FledgePdoException::fromThrowable($e, $this->driver);
        }
    }

    /**
     * Shape a PARAM_LOB value like pdo_mysql and pdo_pgsql: a stream resource is read
     * from its current position to the end, and pdo_pgsql sends the bytes as bytea
     * (binary format), so Postgres gets a PostgresByteA instead of text.
     */
    protected function lobValue(mixed $value): string|PostgresByteA
    {
        $bytes = $this->isResource($value) ? $this->readStream($value) : (string) $value;

        return $this->usesBytea() ? new PostgresByteA($bytes) : $bytes;
    }

    /**
     * Whether the value is a resource, open or closed (is_resource() is false for closed ones).
     */
    private function isResource(mixed $value): bool
    {
        return is_resource($value) || gettype($value) === 'resource (closed)';
    }

    /**
     * Read a PARAM_LOB stream to its end. Like PDO, anything but an open stream is rejected
     * with "SQLSTATE[HY105]: Invalid parameter type: Expected a stream resource".
     *
     * @param  resource  $value
     */
    private function readStream(mixed $value): string
    {
        if (! is_resource($value) || get_resource_type($value) !== 'stream') {
            throw FledgePdoException::implError('HY105', 'Expected a stream resource');
        }

        $bytes = @stream_get_contents($value);

        if ($bytes === false) {
            throw FledgePdoException::implError('HY000', 'Could not read the LOB stream');
        }

        return $bytes;
    }

    /**
     * pdo_pgsql sends PARAM_LOB values as bytea.
     */
    private function usesBytea(): bool
    {
        return $this->pdo instanceof FledgePostgresPdo || ($this->pdo === null && $this->driver === 'pgsql');
    }

    /**
     * Drop what earlier pre-binds left. A connection statement only holds long data on the server
     * when the execute after a bind never left (an execute that reached the server used it up), so
     * like pdo_mysql it gets no COM_STMT_RESET between executes otherwise. A statement pool keeps
     * its binds locally, and its reset() costs no round trip.
     */
    private function resetPrebinds(MysqlStatement $statement): void
    {
        if (($statement instanceof MysqlPooledStatement || $statement instanceof MysqlConnectionStatement)
            && ! $statement->hasPendingLongData()) {
            return;
        }

        $statement->reset();
    }

    /**
     * Pre-bind UUID-formatted string values so Fledge Async sends them as VarString.
     */
    protected function prebindUuids(array &$params): void
    {
        foreach ($params as $key => $value) {
            if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                $this->statement->bind($key, $value);
                unset($params[$key]);
            }
        }
    }

    /**
     * Fetch all rows from the result set.
     *
     * Rows are read inside the guard and shaped outside it, so an exception from a
     * FETCH_CLASS constructor reaches the caller untouched, as it does with PDO.
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->guard(function (): array {
            if ($this->rows !== null) {
                $rows = array_values($this->rows);
                $this->rows = [];

                return $rows;
            }

            $rows = $this->result === null ? [] : iterator_to_array($this->result, false);
            $this->driverRowsRead += count($rows);
            $this->closeUnbufferedCursor();

            return $rows;
        });

        $effectiveMode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        return array_map($this->applyFetchMode(?, $effectiveMode, $args), $rows);
    }

    /**
     * Fetch the next row from the result set.
     */
    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        $row = $this->guard(function (): ?array {
            if ($this->rows === null) {
                $row = $this->result?->fetchRow();

                if ($row === null) {
                    $this->closeUnbufferedCursor();
                } else {
                    $this->driverRowsRead++;
                }

                return $row;
            }

            $row = $this->rows[$this->rowIndex] ?? null;
            unset($this->rows[$this->rowIndex++]);

            return $row;
        });

        if ($row === null) {
            return false;
        }

        $effectiveMode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        return $this->applyFetchMode($row, $effectiveMode, $args);
    }

    /**
     * Return the number of rows affected by the last statement.
     */
    public function rowCount(): int
    {
        return $this->guard(fn (): int => $this->rows !== null ? $this->bufferedRowCount : ($this->result?->getRowCount() ?? 0));
    }

    /**
     * Advance to the next rowset (multi-result queries).
     */
    public function nextRowset(): bool
    {
        return $this->guard(function (): bool {
            if ($this->rows !== null) {
                if ($this->pendingRowsets === []) {
                    return false;
                }

                [$this->bufferedRowCount, $this->rows] = array_shift($this->pendingRowsets);
                $this->rowIndex = 0;

                return true;
            }

            $next = $this->result?->getNextResult();

            if ($next === null) {
                return false;
            }

            $this->result = $next;

            return true;
        });
    }

    /**
     * Build the execute parameter array from bound values.
     *
     * Fledge Async expects a 0-based positional array. Laravel's bindValues() uses
     * 1-based integer keys, which may have gaps: they collapse in key order. Named
     * parameters (:name) are passed as-is.
     */
    protected function buildExecuteParams(): array
    {
        $positional = array_filter($this->bindings, is_int(...), ARRAY_FILTER_USE_KEY);
        ksort($positional);

        return array_values($positional) + array_diff_key($this->bindings, $positional);
    }

    /**
     * Apply the fetch mode to a row from Fledge Async (always associative array).
     */
    protected function applyFetchMode(array $row, int $mode, array $args = []): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => array_merge(array_values($row), $row),
            PDO::FETCH_OBJ => (object) $row,
            PDO::FETCH_COLUMN => reset($row),
            PDO::FETCH_CLASS => $this->fetchAsClass($row, $args),
            default => (object) $row,
        };
    }

    /**
     * Create a class instance from a row.
     */
    protected function fetchAsClass(array $row, array $args): object
    {
        $className = $args[0] ?? \stdClass::class;
        $ctorArgs = $args[1] ?? [];

        $obj = new $className(...$ctorArgs);

        foreach ($row as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }
}
