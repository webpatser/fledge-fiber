<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Sync;

use Fledge\Loop\Durations;
use Time\Duration;

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

    /**
     * @param Duration|float $lockRenewInterval Time between lock renewals, as a `Duration` or in seconds.
     */
    public function withLockRenewInterval(Duration|float $lockRenewInterval): self
    {
        $lockRenewInterval = Durations::toSeconds($lockRenewInterval);

        return clone($this, ['lockRenewInterval' => $lockRenewInterval]);
    }

    /**
     * @param Duration|float $lockTimeout Time to wait for the lock, as a `Duration` or in seconds.
     */
    public function withLockTimeout(Duration|float $lockTimeout): self
    {
        $lockTimeout = Durations::toSeconds($lockTimeout);

        return clone($this, ['lockTimeout' => $lockTimeout]);
    }
}
