<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\Redis\RedisException;

interface RedisConnector
{
    /**
     * @throws RedisException
     */
    public function connect(?Cancellation $cancellation = null): RedisConnection;
}
