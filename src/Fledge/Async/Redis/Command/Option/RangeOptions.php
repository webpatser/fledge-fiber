<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Command\Option;

final class RangeOptions
{
    private ?int $offset = null;
    private ?int $count = null;
    private bool $reverse = false;

    public function withReverseOrder(): self
    {
        return clone($this, ['reverse' => true]);
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

        if ($this->reverse) {
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
