<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Command\Option;

final class SortOptions
{
    private ?string $pattern = null;
    private ?int $offset = null;
    private ?int $count = null;
    private bool $ascending = true;
    private bool $lexicographically = false;

    public function hasPattern(): bool
    {
        return $this->pattern !== null;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function withPattern(string $pattern): self
    {
        return clone($this, ['pattern' => $pattern]);
    }

    public function withoutPattern(): self
    {
        return clone($this, ['pattern' => null]);
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function hasLimit(): bool
    {
        return $this->offset !== null;
    }

    public function withLimit(int $offset, int $count): self
    {
        return clone($this, ['offset' => $offset, 'count' => $count]);
    }

    public function withoutLimit(): self
    {
        return clone($this, ['offset' => null, 'count' => null]);
    }

    public function isAscending(): bool
    {
        return $this->ascending;
    }

    public function isDescending(): bool
    {
        return !$this->ascending;
    }

    public function withAscendingOrder(): self
    {
        return clone($this, ['ascending' => true]);
    }

    public function withDescendingOrder(): self
    {
        return clone($this, ['ascending' => false]);
    }

    public function isLexicographicSorting(): bool
    {
        return $this->lexicographically;
    }

    public function withLexicographicSorting(): self
    {
        return clone($this, ['lexicographically' => true]);
    }

    public function withNumericSorting(): self
    {
        return clone($this, ['lexicographically' => false]);
    }

    /**
     * @return list<int|string>
     */
    public function toQuery(): array
    {
        $payload = [];

        $pattern = $this->getPattern();
        if ($pattern !== null) {
            $payload[] = 'BY';
            $payload[] = $pattern;
        }

        if ($this->hasLimit()) {
            $offset = $this->getOffset();
            $count = $this->getCount();

            \assert($offset !== null);
            \assert($count !== null);

            $payload[] = 'LIMIT';
            $payload[] = $offset;
            $payload[] = $count;
        }

        if ($this->isDescending()) {
            $payload[] = 'DESC';
        }

        if ($this->isLexicographicSorting()) {
            $payload[] = 'ALPHA';
        }

        return $payload;
    }
}
