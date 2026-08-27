<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Redis\RedisException;

/**
 * Thrown when a connection is lost while a non-idempotent command was in
 * flight: the command may have executed on the server, so resending it after
 * a reconnect could apply it twice.
 */
final class RedisInFlightCommandException extends RedisException
{
}
