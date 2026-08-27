<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Redis\RedisException;

/**
 * Thrown when a command response does not arrive within the configured
 * read_timeout. The message deliberately contains "read error on connection"
 * so the same transient markers that match phpredis read timeouts match here.
 */
final class RedisTimeoutException extends RedisException
{
}
