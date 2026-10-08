<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Closure;
use Fiber;
use PDOException;
use stdClass;

/**
 * Lease state of one pooled PDO, shared with the statements it prepares.
 *
 * It lives in its own object so statements can reference it through
 * PDO::ATTR_STATEMENT_CLASS without the PDO referencing itself (that cycle
 * would keep discarded connections open until the cycle collector runs).
 *
 * The guard is unarmed until the pool leases the PDO for the first time, so
 * the connector can run its session setup (SET NAMES, sql_mode, ...) freely.
 * Once armed, every guarded call must come from the lease owner: the fiber
 * that acquired it, or the main context.
 */
final class LeaseGuard
{
    private static ?stdClass $mainContext = null;

    private ?object $owner = null;

    private bool $armed = false;

    private bool $broken = false;

    /**
     * The current lease owner key: the running fiber, or a fixed token for
     * the main context.
     */
    public static function currentOwner(): object
    {
        return Fiber::getCurrent() ?? (self::$mainContext ??= new stdClass);
    }

    public function assign(object $owner): void
    {
        $this->owner = $owner;
        $this->armed = true;
    }

    public function clear(): void
    {
        $this->owner = null;
    }

    public function owner(): ?object
    {
        return $this->owner;
    }

    /**
     * Whether a guarded call ended abnormally (an exception other than
     * PDOException, or the fiber was destroyed mid-call). The wire protocol
     * may be mid-exchange, so the connection must never be reused.
     */
    public function isBroken(): bool
    {
        return $this->broken;
    }

    public function markBroken(): void
    {
        $this->broken = true;
    }

    /**
     * @throws LeaseOwnershipException
     */
    public function assertOwner(): void
    {
        if (! $this->armed) {
            return;
        }

        if ($this->owner === null) {
            throw new LeaseOwnershipException(
                'This PDO was released back to the native connection pool and is no longer leased. '
                .'Do not keep PDO or PDOStatement references past the query or transaction that produced them.'
            );
        }

        if ($this->owner !== self::currentOwner()) {
            throw new LeaseOwnershipException(
                'This PDO is leased to another fiber. Each fiber gets its own connection from the native pool: '
                .'do not share PDO, PDOStatement or cursor() references between fibers.'
            );
        }
    }

    /**
     * Run one pdo_mysql call on behalf of the lease owner.
     *
     * A PDOException means the server or driver reported an error and the
     * connection is still in a known state. Anything else escaping the call
     * (a fiberio waiter exception, a cancellation, the fiber being destroyed
     * while parked) marks the connection broken so the pool discards it.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws LeaseOwnershipException
     */
    public function run(Closure $call): mixed
    {
        $this->assertOwner();

        $completed = false;

        try {
            $result = $call();
            $completed = true;

            return $result;
        } catch (PDOException $e) {
            $completed = true;

            throw $e;
        } finally {
            if (! $completed) {
                $this->broken = true;
            }
        }
    }
}
