<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use Fledge\Async\Cancellation;
use Fledge\Async\Closable;

interface ServerSocket extends Closable
{
    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?Socket;

    public function getAddress(): SocketAddress;

    public function getBindContext(): BindContext;
}
