<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use LogicException;

/**
 * A pooled PDO (or a statement or cursor built on it) was used by a fiber
 * that does not hold its lease.
 *
 * This is always a programming error: a PDO, statement or cursor generator
 * escaped the fiber that leased it. It is thrown before pdo_mysql touches the
 * socket, so it never turns into fiberio's "stream is in use by another fiber"
 * Error or into a PDOException that Laravel would treat as a lost connection.
 */
class LeaseOwnershipException extends LogicException {}
