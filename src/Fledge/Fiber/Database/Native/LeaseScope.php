<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Throwable;

/**
 * Per-fiber (or main context) connection state of a native Connection.
 *
 * - `$depth` counts the open lease scopes: run(), cursor() generators and the
 *   transaction methods. Leases are released when it drops to zero while
 *   `$transactions` is zero.
 * - `$transactions` is the fiber's Laravel transaction level (the hooked
 *   `$transactions` property reads and writes it).
 * - `$leases` holds at most one lease per PDO slot (write, read, direct).
 *
 * Scopes are stored in a FiberLocal. Revolt reuses callback fibers and clears
 * FiberLocal storage after every callback, so a scope a callback left behind
 * (an abandoned transaction, a PDO taken outside any scope) is destroyed
 * before the next callback runs on that fiber. The destructor also covers a
 * fiber that dies while still holding a lease.
 *
 * @internal
 */
final class LeaseScope
{
    public int $depth = 0;

    public int $transactions = 0;

    /**
     * The slot of the most recently handed out PDO, used to pick the broken
     * lease on reconnect().
     */
    public ?string $lastSlot = null;

    /** @var array<string, Lease> */
    public array $leases = [];

    public function __destruct()
    {
        // No open scope and no transaction: an idle lease (a PDO taken outside
        // any scope) goes back to the pool, which still refuses a connection
        // that is broken or inside a transaction.
        $idle = $this->depth === 0 && $this->transactions === 0;

        foreach ($this->leases as $lease) {
            try {
                // Otherwise the owner is gone mid-transaction or mid-query:
                // never hand this connection to anyone else.
                $idle ? $lease->release() : $lease->discard();
            } catch (Throwable) {
                // Destructors may run during shutdown, after the loop is gone.
            }
        }

        $this->leases = [];
    }
}
