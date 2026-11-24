<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database;

use WeakMap;

/**
 * Process-wide registry of live pools. Pools are held weakly, so an entry
 * disappears when the pool is garbage collected.
 */
final class PoolStatsRegistry
{
    /** @var WeakMap<PoolStats, string>|null */
    private static ?WeakMap $pools = null;

    public static function register(PoolStats $pool, string $name): void
    {
        self::$pools ??= new WeakMap;
        self::$pools[$pool] = $name;
    }

    /**
     * Live, open pools with their connection names. Names repeat: a read/write
     * split, per-task connections and reconnects each hold their own pool under
     * one connection name, so consumers must sum the numbers per name. Closed
     * pools are left out.
     *
     * @return list<array{name: string, pool: PoolStats}>
     */
    public static function all(): array
    {
        $pools = [];

        foreach (self::$pools ?? [] as $pool => $name) {
            if (! $pool->isClosed()) {
                $pools[] = ['name' => $name, 'pool' => $pool];
            }
        }

        return $pools;
    }
}
