<?php

namespace Tests\Fledge\database\Stubs;

use Fledge\Async\Database\SqlResult;

/**
 * In-memory SqlResult implementation for unit tests.
 *
 * Replaces Mockery mocks of SqlResult so tests exercise the real interface contract.
 */
class FakeRowResult implements SqlResult, \IteratorAggregate
{
    private int $pos = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        private array $rows,
        private ?SqlResult $nextResult = null,
    ) {
    }

    public function fetchRow(): ?array
    {
        return $this->rows[$this->pos++] ?? null;
    }

    public function getNextResult(): ?SqlResult
    {
        return $this->nextResult;
    }

    public function getRowCount(): int
    {
        return count($this->rows);
    }

    public function getColumnCount(): ?int
    {
        return empty($this->rows) ? 0 : count($this->rows[0]);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rows);
    }
}
