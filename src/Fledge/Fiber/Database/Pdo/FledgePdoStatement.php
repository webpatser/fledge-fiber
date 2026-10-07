<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Postgres\PostgresByteA;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
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
     * Create a new Fledge Async PDO statement shim.
     */
    public function __construct(?SqlStatement $statement = null, ?SqlResult $result = null, ?FledgePdo $pdo = null, ?string $driver = null)
    {
        $this->statement = $statement;
        $this->result = $result;
        $this->pdo = $pdo;
        $this->driver = $driver;
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
        return $this->guard(function () use ($params): bool {
            try {
                $executeParams = $params ?? $this->buildExecuteParams();

                // Workaround for Fledge Async MySQL sending string params as LONG_BLOB type,
                // which breaks MariaDB native UUID columns. Pre-binding via bind()
                // sends the data as VarString instead (see Fledge Async MySQL#142).
                if ($this->statement instanceof MysqlStatement) {
                    $this->prebindUuids($executeParams);
                }

                if ($this->statement !== null) {
                    // Drop the previous result before executing again: PHP evaluates the
                    // right-hand side before releasing the old value, so the stale result
                    // would still pin a pooled connection while execute() waits for one.
                    $this->result = null;
                    $this->result = $this->statement->execute($executeParams);

                    $this->pdo?->trackLastInsertId($this->result);
                }
            } finally {
                $this->bindings = [];
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
            $this->result = null;

            return true;
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
        $rows = $this->guard(fn (): array => $this->result === null ? [] : iterator_to_array($this->result, false));

        $effectiveMode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        return array_map(fn (array $row): mixed => $this->applyFetchMode($row, $effectiveMode, $args), $rows);
    }

    /**
     * Fetch the next row from the result set.
     */
    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        $row = $this->guard(fn (): ?array => $this->result?->fetchRow());

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
        return $this->guard(fn (): int => $this->result?->getRowCount() ?? 0);
    }

    /**
     * Advance to the next rowset (multi-result queries).
     */
    public function nextRowset(): bool
    {
        return $this->guard(function (): bool {
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
     * 1-based integer keys. Named parameters (:name) are passed as-is.
     */
    protected function buildExecuteParams(): array
    {
        if (empty($this->bindings)) {
            return [];
        }

        if (array_all(array_keys($this->bindings), fn ($key): bool => is_int($key))) {
            ksort($this->bindings);

            return array_values($this->bindings);
        }

        return $this->bindings;
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
