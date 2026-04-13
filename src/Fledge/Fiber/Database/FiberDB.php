<?php

namespace Fledge\Fiber\Database;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/**
 * Fiber-powered concurrent database operations.
 *
 * Runs multiple database queries/closures in parallel Fibers using the
 * Revolt event loop. Each operation suspends its Fiber while waiting
 * for I/O, allowing other operations to progress concurrently.
 *
 * Requires an Fledge-based database driver (fledge-mysql, fledge-pgsql, etc.)
 * for true non-blocking I/O. With standard PDO drivers, operations still
 * run but without actual concurrency.
 */
class FiberDB
{
    /**
     * Run multiple database operations concurrently in Fibers.
     *
     * Returns array of results in the same order as the callbacks.
     * Total wall-clock time equals the slowest operation, not the sum.
     *
     * @param  callable  ...$operations
     * @return array
     *
     * @example
     * [$users, $posts, $count] = FiberDB::concurrent(
     *     fn() => User::where('active', true)->get(),
     *     fn() => Post::latest()->limit(10)->get(),
     *     fn() => Comment::where('approved', false)->count(),
     * );
     */
    public static function concurrent(callable ...$operations): array
    {
        $futures = array_map(fn (callable $op) => async($op), $operations);

        return await($futures);
    }
}
