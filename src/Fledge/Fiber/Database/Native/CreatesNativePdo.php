<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Illuminate\Database\Connectors\Connector;
use Pdo\Mysql;

/**
 * Mixed into Laravel's stock MySqlConnector / MariaDbConnector so that every
 * physical connection is a NativePdo, while DSN building, SSL attributes,
 * sql_mode, isolation level, timezone and init commands stay stock.
 *
 * @mixin Connector
 */
trait CreatesNativePdo
{
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        // Without pdo_mysql there is no \Pdo\Mysql to extend; let the stock
        // path raise its usual "could not find driver" PDOException.
        if (! class_exists(Mysql::class, false)) {
            return parent::createPdoConnection($dsn, $username, $password, $options);
        }

        return new NativePdo($dsn, $username, $password, $options);
    }
}
