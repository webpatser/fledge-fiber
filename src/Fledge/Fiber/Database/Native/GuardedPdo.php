<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

/**
 * A PDO that carries a LeaseGuard. NativePdoPool arms the guard on every
 * lease, clears it on release and discards the PDO when the guard reports it
 * broken. Plain PDOs are pooled too, just without these checks.
 */
interface GuardedPdo
{
    public function leaseGuard(): LeaseGuard;
}
