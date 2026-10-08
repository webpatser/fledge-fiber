<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use PDO;

/**
 * One PDO leased from a pool to one fiber (or the main context).
 *
 * @internal
 */
final class Lease
{
    public function __construct(
        public readonly NativePdoPool $pool,
        public readonly PDO $pdo,
    ) {}

    public function isBroken(): bool
    {
        return $this->pdo instanceof GuardedPdo && $this->pdo->leaseGuard()->isBroken();
    }

    public function release(): void
    {
        if ($this->pool->isLeased($this->pdo)) {
            $this->pool->release($this->pdo);
        }
    }

    public function discard(): void
    {
        if ($this->pool->isLeased($this->pdo)) {
            $this->pool->discard($this->pdo);
        }
    }
}
