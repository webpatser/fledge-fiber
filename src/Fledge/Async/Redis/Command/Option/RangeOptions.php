<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Command\Option;

final class RangeOptions
{
    private ?int $offset = null;
    private ?int $count = null;
    private \SortDirection $direction = \SortDirection::Ascending;

    public function withReverseOrder(): self
    {
        return clone($this, ['direction' => \SortDirection::Descending]);
    }

    /**
     * Descending is the same as {@see withReverseOrder()} (REV); ascending is the default order.
     *
     * @param \SortDirection|'asc'|'desc' $direction
     *
     * @throws \InvalidArgumentException
     */
    public function withDirection(\SortDirection|string $direction): self
    {
        $direction = match (true) {
            $direction instanceof \SortDirection => $direction,
            \strtolower($direction) === 'asc' => \SortDirection::Ascending,
            \strtolower($direction) === 'desc' => \SortDirection::Descending,
            default => throw new \InvalidArgumentException('Range direction must be a SortDirection, "asc" or "desc".'),
        };

        return clone($this, ['direction' => $direction]);
    }

    public function getDirection(): \SortDirection
    {
        return $this->direction;
    }

    public function withLimit(int $offset, int $count): self
    {
        return clone($this, ['offset' => $offset, 'count' => $count]);
    }

    /**
     * @return list<string|int>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->direction === \SortDirection::Descending) {
            $query[] = "REV";
        }

        if ($this->offset !== null) {
            \assert($this->count !== null);

            $query[] = "LIMIT";
            $query[] = $this->offset;
            $query[] = $this->count;
        }

        return $query;
    }
}
