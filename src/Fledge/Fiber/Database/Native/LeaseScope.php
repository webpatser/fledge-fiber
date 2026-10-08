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
 * Scopes are keyed by fiber in a WeakMap. Revolt reuses callback fibers, so
 * leases never outlive the outermost scope; the destructor is only a safety
 * net for a fiber that dies while still holding one.
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
        foreach ($this->leases as $lease) {
            try {
                // The owner is gone, possibly mid-transaction or mid-query:
                // never hand this connection to anyone else.
                $lease->discard();
            } catch (Throwable) {
                // Destructors may run during shutdown, after the loop is gone.
            }
        }

        $this->leases = [];
    }
}
