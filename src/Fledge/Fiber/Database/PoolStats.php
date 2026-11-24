<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database;

/**
 * Read-only pool numbers, for metrics and diagnostics.
 */
interface PoolStats
{
    /**
     * Open connections (idle + in use + being opened).
     */
    public function count(): int;

    public function getIdleCount(): int;

    public function getMaxSize(): int;

    /**
     * Callers currently waiting for a connection.
     */
    public function getWaitingCount(): int;

    /**
     * Whether the pool was closed; a closed pool hands out no connections.
     */
    public function isClosed(): bool;
}
