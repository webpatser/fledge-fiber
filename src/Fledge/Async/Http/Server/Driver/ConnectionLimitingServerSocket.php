<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Cancellation;
use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\ServerSocket;
use Fledge\Async\Stream\Socket;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Sync\Semaphore;

final readonly class ConnectionLimitingServerSocket implements ServerSocket
{
    public function __construct(
        private ServerSocket $socketServer,
        private Semaphore $semaphore,
    ) {
    }

    public function accept(?Cancellation $cancellation = null): ?Socket
    {
        $lock = $this->semaphore->acquire();

        $socket = $this->socketServer->accept($cancellation);
        if (!$socket) {
            $lock->release();
            return null;
        }

        $socket->onClose($lock->release(...));

        return $socket;
    }

    public function close(): void
    {
        $this->socketServer->close();
    }

    public function isClosed(): bool
    {
        return $this->socketServer->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socketServer->onClose($onClose);
    }

    public function getAddress(): SocketAddress
    {
        return $this->socketServer->getAddress();
    }

    public function getBindContext(): BindContext
    {
        return $this->socketServer->getBindContext();
    }
}
