<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use PDOStatement;

/**
 * Statement class installed on every NativePdo via PDO::ATTR_STATEMENT_CLASS.
 *
 * execute() is the call that talks to the server, so it carries the lease
 * owner assertion. Fetches stay native: with buffered queries (the Laravel
 * default) they only read client-side memory, and cursor() asserts the owner
 * per row at the connection level.
 */
class NativePdoStatement extends PDOStatement
{
    protected function __construct(private readonly LeaseGuard $leaseGuard) {}

    public function execute(?array $params = null): bool
    {
        return $this->leaseGuard->run(fn () => parent::execute($params));
    }
}
