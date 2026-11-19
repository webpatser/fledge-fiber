<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Internal;

use Fledge\Async\DeferredFuture;

/**
 * A queued command awaiting its response, tracked by ReconnectingRedisLink.
 * $sent records whether the command reached a connection: only entries that
 * were actually sent risk having executed server-side when the connection
 * drops.
 *
 * $multi points at the MULTI entry of the transaction this command was
 * queued in (MULTI itself, the queued commands, and the closing EXEC or
 * DISCARD), or is null outside a transaction. Once that MULTI was sent on a
 * connection that is lost, the server-side transaction is gone and none of
 * its commands may be resent or sent on the new connection.
 * $transactionFailed is only used on a MULTI entry: set once the link failed
 * any command of that transaction, so its caller already got an exception.
 *
 * @internal
 */
final class PendingRedisCommand
{
    public bool $sent = false;

    public ?PendingRedisCommand $multi = null;

    public bool $transactionFailed = false;

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
