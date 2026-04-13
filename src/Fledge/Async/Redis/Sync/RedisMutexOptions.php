<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Sync;

final class RedisMutexOptions
{
    private string $keyPrefix = '';
    private float $lockRenewInterval = 1;
    private float $lockExpiration = 3;
    private float $lockTimeout = 10;

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getLockExpiration(): float
    {
        return $this->lockExpiration;
    }

    public function getLockRenewInterval(): float
    {
        return $this->lockRenewInterval;
    }

    public function getLockTimeout(): float
    {
        return $this->lockTimeout;
    }

    public function withKeyPrefix(string $keyPrefix): self
    {
        return clone($this, ['keyPrefix' => $keyPrefix]);
    }

    public function withLockExpiration(float $lockExpiration): self
    {
        return clone($this, ['lockExpiration' => $lockExpiration]);
    }

    public function withLockRenewInterval(float $lockRenewInterval): self
    {
        return clone($this, ['lockRenewInterval' => $lockRenewInterval]);
    }

    public function withLockTimeout(float $lockTimeout): self
    {
        return clone($this, ['lockTimeout' => $lockTimeout]);
    }
}
