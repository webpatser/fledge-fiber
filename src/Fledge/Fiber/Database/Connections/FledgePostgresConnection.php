<?php

namespace Fledge\Fiber\Database\Connections;

use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\PostgresConnection;

/**
 * PostgreSQL connection using Fledge Async for non-blocking Fiber-based I/O.
 */
class FledgePostgresConnection extends PostgresConnection
{
    protected function prepared($statement)
    {
        $statement->setFetchMode($this->fetchMode);

        $this->event(new StatementPrepared($this, $statement));

        return $statement;
    }
}
