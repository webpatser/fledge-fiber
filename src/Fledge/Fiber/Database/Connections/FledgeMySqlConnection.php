<?php

namespace Fledge\Fiber\Database\Connections;

use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\MySqlConnection;

/**
 * MySQL connection using Fledge Async for non-blocking Fiber-based I/O.
 *
 * Extends MySqlConnection to inherit all grammars, processors, and schema
 * builder logic. Overrides only the methods that have hard PDOStatement
 * type constraints.
 */
class FledgeMySqlConnection extends MySqlConnection
{
    /**
     * Configure a PDOStatement or FledgePdoStatement after preparing.
     *
     * Drops the parent's PDOStatement type hint to accept our shim.
     */
    protected function prepared($statement)
    {
        $statement->setFetchMode($this->fetchMode);

        $this->event(new StatementPrepared($this, $statement));

        return $statement;
    }

    /**
     * Determine if the connected database is a MariaDB database.
     */
    public function isMaria()
    {
        $version = $this->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return str_contains($version, 'MariaDB');
    }

    /**
     * Run an insert statement against the database.
     */
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
