<?php

namespace Tests\Fledge\database\Stubs;

use Fledge\Async\Cancellation;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnector;

/**
 * SqlConnector stub that hands out FakeSqlConnection instances.
 */
class FakeSqlConnector implements SqlConnector
{
    public ?FakeSqlConnection $lastConnection = null;

    public function __construct(private ?string $failOn = null) {}

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqlConnection
    {
        return $this->lastConnection = new FakeSqlConnection($config, $this->failOn);
    }
}
