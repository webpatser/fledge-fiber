<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

/**
 * A PDO that carries a LeaseGuard. NativePdoPool arms the guard on every
 * lease, clears it on release and discards the PDO when the guard reports it
 * broken; a lease whose guard recorded a possible session change is discarded
 * instead of released (LeaseScope::giveBack()).
 *
 * A pooled PDO that is not a GuardedPdo cannot report what ran on it, so
 * every hand-back discards it: it serves exactly one lease.
 */
interface GuardedPdo
{
    public function leaseGuard(): LeaseGuard;
}
