<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Internal;

use Fledge\Async\DeferredFuture;

/**
 * A queued command awaiting its response, tracked by ReconnectingRedisLink.
 * $sent records whether the command reached a connection: only entries that
 * were actually sent risk having executed server-side when the connection
 * drops.
 *
 * @internal
 */
final class PendingRedisCommand
{
    public bool $sent = false;

    /**
     * @param  list<string>  $parameters
     */
    public function __construct(
        public readonly DeferredFuture $deferred,
        public readonly string $command,
        public readonly array $parameters,
    ) {
    }
}
