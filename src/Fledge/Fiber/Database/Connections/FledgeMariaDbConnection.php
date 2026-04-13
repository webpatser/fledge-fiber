<?php

namespace Fledge\Fiber\Database\Connections;

use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\MariaDbConnection;

/**
 * MariaDB connection using Fledge Async for non-blocking Fiber-based I/O.
 *
 * Uses the same Fledge Async MySQL transport as MySQL (wire-compatible).
 */
class FledgeMariaDbConnection extends MariaDbConnection
{
    protected function prepared($statement)
    {
        $statement->setFetchMode($this->fetchMode);

        $this->event(new StatementPrepared($this, $statement));

        return $statement;
    }

    public function insert($query, $bindings = [], $sequence = null)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($sequence) {
            if ($this->pretending()) {
                return true;
            }

            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            $result = $statement->execute();

            $this->lastInsertId = $this->getPdo()->lastInsertId($sequence);

            return $result;
        });
    }
}
