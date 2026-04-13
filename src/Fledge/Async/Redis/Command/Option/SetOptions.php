<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Command\Option;

final class SetOptions
{
    private ?int $ttl = null;
    private string $ttlUnit = 'EX';
    private ?string $existenceFlag = null;

    public function withTtl(int $seconds): self
    {
        return clone($this, ['ttl' => $seconds, 'ttlUnit' => 'EX']);
    }

    public function withTtlInMillis(int $millis): self
    {
        return clone($this, ['ttl' => $millis, 'ttlUnit' => 'PX']);
    }

    public function withoutOverwrite(): self
    {
        return clone($this, ['existenceFlag' => 'NX']);
    }

    public function withoutCreation(): self
    {
        return clone($this, ['existenceFlag' => 'XX']);
    }

    /**
     * @return list<int|string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->ttl !== null) {
            $query[] = $this->ttlUnit;
            $query[] = $this->ttl;
        }

        if ($this->existenceFlag !== null) {
            $query[] = $this->existenceFlag;
        }

        return $query;
    }
}
