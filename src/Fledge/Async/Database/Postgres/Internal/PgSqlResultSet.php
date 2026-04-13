<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Future;
use Fledge\Async\Database\Postgres\PostgresResult;

/**
 * @internal
 * @psalm-import-type TRowType from PostgresResult
 * @implements \IteratorAggregate<int, TRowType>
 */
final readonly class PgSqlResultSet implements PostgresResult, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private \Iterator $iterator;

    private int $rowCount;

    private int $columnCount;

    /**
     * @param array<int, PgSqlType> $types
     * @param Future<PostgresResult|null> $nextResult
     */
    public function __construct(
        \PgSql\Result $handle,
        array $types,
        private Future $nextResult,
    ) {
        $this->rowCount = \pg_num_rows($handle);
        $this->columnCount = \pg_num_fields($handle);

        $this->iterator = PgSqlResultIterator::iterate($handle, $types);
    }

    #[\Override]
    public function fetchRow(): ?array
    {
        if (!$this->iterator->valid()) {
            return null;
        }

        $current = $this->iterator->current();
        $this->iterator->next();
        return $current;
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        return $this->iterator;
    }

    #[\Override]
    public function getNextResult(): ?PostgresResult
    {
        return $this->nextResult->await();
    }

    /**
     * @return int Number of rows returned.
     */
    #[\Override]
    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * @return int Number of columns returned.
     */
    #[\Override]
    public function getColumnCount(): int
    {
        return $this->columnCount;
    }
}
