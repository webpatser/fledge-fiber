<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\MariaDbConnector;

/**
 * MariaDB twin of NativeMySqlConnector: physical PDOs come from Laravel's
 * MariaDbConnector, which differs from MySqlConnector in its strict sql_mode.
 */
class NativeMariaDbConnector extends NativeMySqlConnector
{
    protected function stockConnector(): ConnectorInterface
    {
        return new class extends MariaDbConnector
        {
            use CreatesNativePdo;
        };
    }
}
