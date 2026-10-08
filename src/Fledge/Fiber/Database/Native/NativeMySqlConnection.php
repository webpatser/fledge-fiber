<?php

namespace Fledge\Fiber\Database\Native;

use Illuminate\Database\MySqlConnection;

/**
 * MySQL connection for the `fledge-mysql-native` driver: a pool of stock
 * pdo_mysql connections made fiber-aware by fiberio, one leased per fiber,
 * with per-fiber transaction levels and transaction callbacks.
 *
 * See UsesNativePdoPool for the lease and transaction rules.
 */
class NativeMySqlConnection extends MySqlConnection
{
    use UsesNativePdoPool;
}
