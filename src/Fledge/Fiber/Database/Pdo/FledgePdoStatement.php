<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use PDO;

/**
 * PDOStatement-compatible shim wrapping an Fledge Async SQL statement/result.
 *
 * Implements the subset of PDOStatement methods used by Illuminate\Database\Connection:
 * setFetchMode, execute, fetchAll, fetch, bindValue, rowCount, nextRowset.
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
     * Create a new Fledge Async PDO statement shim.
     */
    public function __construct(?SqlStatement $statement = null, ?SqlResult $result = null, ?FledgePdo $pdo = null)
    {
        $this->statement = $statement;
        $this->result = $result;
        $this->pdo = $pdo;
    }

    /**
     * Set the default fetch mode for this statement.
     */
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;
        $this->fetchModeArgs = $args;

        return true;
    }

    /**
     * Bind a value to a parameter.
     */
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        if ($type === PDO::PARAM_INT) {
            $value = (int) $value;
        } elseif ($type === PDO::PARAM_BOOL) {
            $value = (bool) $value;
        } elseif ($type === PDO::PARAM_NULL) {
            $value = null;
        } elseif ($type === PDO::PARAM_STR && $value !== null) {
            $value = (string) $value;
        }

        $this->bindings[$param] = $value;

        return true;
    }

    /**
     * Execute the prepared statement.
     *
     * Fledge Async uses positional parameters (0-based array). Laravel binds with
     * 1-based integer keys from bindValues(). We convert accordingly.
     */
    public function execute(?array $params = null): bool
    {
        $executeParams = $params ?? $this->buildExecuteParams();

        // Workaround for Fledge Async MySQL sending string params as LONG_BLOB type,
        // which breaks MariaDB native UUID columns. Pre-binding via bind()
        // sends the data as VarString instead (see Fledge Async MySQL#142).
        if ($this->statement instanceof MysqlStatement) {
            $this->prebindUuids($executeParams);
        }

        if ($this->statement !== null) {
            $this->result = $this->statement->execute($executeParams);
            $this->pdo?->trackLastInsertId($this->result);
        }

        $this->bindings = [];

        return true;
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
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->result === null) {
            return [];
        }

        $effectiveMode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;
        $rows = [];

        foreach ($this->result as $row) {
            $rows[] = $this->applyFetchMode($row, $effectiveMode, $args);
        }

        return $rows;
    }

    /**
     * Fetch the next row from the result set.
     */
    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        if ($this->result === null) {
            return false;
        }

        $row = $this->result->fetchRow();

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
        return $this->result?->getRowCount() ?? 0;
    }

    /**
     * Advance to the next rowset (multi-result queries).
     */
    public function nextRowset(): bool
    {
        if ($this->result === null) {
            return false;
        }

        $next = $this->result->getNextResult();

        if ($next === null) {
            return false;
        }

        $this->result = $next;

        return true;
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

        $allInt = true;

        foreach (array_keys($this->bindings) as $key) {
            if (! is_int($key)) {
                $allInt = false;
                break;
            }
        }

        if ($allInt) {
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
