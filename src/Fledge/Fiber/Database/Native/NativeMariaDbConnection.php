<?php

namespace Fledge\Fiber\Database\Native;

use Illuminate\Database\MariaDbConnection;

/**
 * MariaDB connection for the `fledge-mariadb-native` driver: a pool of stock
 * pdo_mysql connections made fiber-aware by fiberio, one leased per fiber,
 * with per-fiber transaction levels and transaction callbacks.
 *
 * See UsesNativePdoPool for the lease and transaction rules.
 */
class NativeMariaDbConnection extends MariaDbConnection
{
    use UsesNativePdoPool;
}
