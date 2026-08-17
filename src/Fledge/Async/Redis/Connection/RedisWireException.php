<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Redis\RedisException;

/**
 * A wire-protocol parse failure: the byte stream from the server could not be
 * decoded into RESP frames.
 *
 * Distinct from a connection failure because it is not transient for the
 * commands in flight: the response stream is corrupt, so resending the same
 * queued commands on a fresh connection would fail the same way.
 * ReconnectingRedisLink fails the pending commands on this exception instead
 * of resending them.
 */
class RedisWireException extends RedisException
{
}
