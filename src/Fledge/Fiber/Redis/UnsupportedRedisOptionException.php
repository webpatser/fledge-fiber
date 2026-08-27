<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\RedisException;

/**
 * Thrown when a Laravel redis configuration option would silently change
 * data or routing semantics if ignored. Failing loudly at connect time beats
 * corrupting a keyspace or reading from an unexpected replica.
 */
class UnsupportedRedisOptionException extends RedisException
{
}
