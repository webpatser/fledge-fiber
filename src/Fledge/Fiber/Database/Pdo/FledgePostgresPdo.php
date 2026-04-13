<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlConnectionPool;
use PDO;

/**
 * PostgreSQL-specific FledgePdo implementation wrapping PostgresConnectionPool.
 *
 * Translates PDO-style ? placeholders to PostgreSQL $N style.
 */
class FledgePostgresPdo extends FledgePdo
{
    public function __construct(SqlConnectionPool $pool)
    {
        parent::__construct($pool);
    }

    protected function getVersionQuery(): string
    {
        return 'SELECT version()';
    }

    protected function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * Quote a string for use in a query.
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        if ($type === PDO::PARAM_INT) {
            return (string) (int) $string;
        }

        $escaped = str_replace("'", "''", $string);
        $escaped = str_replace('\\', '\\\\', $escaped);

        return "'{$escaped}'";
    }

    public function trackLastInsertId(mixed $result): void
    {
        // PostgreSQL uses RETURNING clauses rather than a global last_insert_id.
    }

    /**
     * Prepare a statement, translating ? placeholders to $N for PostgreSQL.
     */
    public function prepare(string $query, array $options = []): FledgePdoStatement
    {
        $query = $this->convertPlaceholders($query);

        $executor = $this->transaction ?? $this->pool;
        $statement = $executor->prepare($query);

        return new FledgePdoStatement($statement, pdo: $this);
    }

    /**
     * Convert PDO-style ? placeholders to PostgreSQL $N style.
     *
     * Respects quoted strings and identifiers.
     */
    protected function convertPlaceholders(string $query): string
    {
        $result = '';
        $paramIndex = 1;
        $len = strlen($query);
        $i = 0;

        while ($i < $len) {
            $char = $query[$i];

            if ($char === "'" || $char === '"') {
                $end = $this->findEndOfQuotedString($query, $i, $char);
                $result .= substr($query, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '?') {
                $result .= '$'.$paramIndex;
                $paramIndex++;
                $i++;
                continue;
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * Find the end position of a quoted string (handles escaped quotes).
     */
    protected function findEndOfQuotedString(string $query, int $start, string $quote): int
    {
        $len = strlen($query);
        $i = $start + 1;

        while ($i < $len) {
            if ($query[$i] === $quote) {
                if ($i + 1 < $len && $query[$i + 1] === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }

            if ($query[$i] === '\\') {
                $i += 2;
                continue;
            }

            $i++;
        }

        return $len;
    }
}
