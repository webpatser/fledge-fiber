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
 * - A lease whose guard recorded a possible session change is pinned: it is
 *   kept when the outermost scope ends (see shouldPin()), so the fiber's
 *   following statements on that slot run on the same connection and see
 *   their own session state. It is discarded, never released, when the pin
 *   ends (see giveBack()).
 *
 * Scopes are stored in a FiberLocal. Revolt reuses callback fibers and clears
 * FiberLocal storage after every callback, so a scope a callback left behind
 * (a pinned lease, an abandoned transaction, a PDO taken outside any scope)
 * is destroyed before the next callback runs on that fiber: that is where a
 * fiber's pin ends. The destructor also covers a fiber that dies while still
 * holding a lease. The main context's scope lives as long as the connection,
 * so its pin lasts until disconnect() (or a reconnect), as with one stock PDO.
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

    /**
     * Hand a lease back for good (the pin ended, or the slot is replaced):
     * released to the pool when its session is as connect() left it,
     * discarded otherwise.
     *
     * A lease is dirty when its guard recorded a possible session change
     * (user or session variables, temporary tables, named locks, another
     * current database): NativePdo judges every statement where it reaches
     * the connection, see NativePdo::leavesSessionUnchanged(). A plain PDO
     * cannot report what ran on it, so it always counts as dirty.
     *
     * pdo_mysql offers no COM_RESET_CONNECTION (nor COM_CHANGE_USER), and
     * neither MySQL nor MariaDB has an SQL statement that resets a session, so
     * the only complete reset is a new session: a dirty lease is discarded
     * (its connection closed) and the pool opens a fresh one, through the
     * stock connector with the configured database, for a later lessee.
     */
    public function giveBack(Lease $lease): void
    {
        self::isDirty($lease) ? $lease->discard() : $lease->release();
    }

    /**
     * Whether a lease stays with this scope when its outermost scope ends:
     * its session may have changed and it still works. Its connection then
     * counts against pool_size until the pin ends.
     *
     * A closed pool does not end the pin: another context may disconnect
     * while this fiber's statement is in flight, and dropping the lease here
     * would let the fiber's next statement continue silently on a fresh
     * session. Kept, the pin makes that statement throw
     * LostConnectionException once, and the lease is discarded there (or by
     * this scope's destructor), so its connection is closed exactly once.
     * A clean lease on a closed pool is handed back as before.
     */
    public function shouldPin(Lease $lease): bool
    {
        return self::isDirty($lease) && ! $lease->isBroken();
    }

    private static function isDirty(Lease $lease): bool
    {
        return ! $lease->pdo instanceof GuardedPdo || $lease->pdo->leaseGuard()->sessionChanged();
    }

    public function __destruct()
    {
        // No open scope and no transaction: an idle lease (a PDO taken outside
        // any scope) goes back to the pool, which still refuses a connection
        // that is broken or inside a transaction.
        $idle = $this->depth === 0 && $this->transactions === 0;

        foreach ($this->leases as $lease) {
            try {
                // Otherwise the owner is gone mid-transaction or mid-query:
                // never hand this connection to anyone else. A dirty idle
                // lease is discarded as well.
                $idle ? $this->giveBack($lease) : $lease->discard();
            } catch (Throwable) {
                // Destructors may run during shutdown, after the loop is gone.
            }
        }

        $this->leases = [];
    }
}
