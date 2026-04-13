<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Stream\Socket;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;

final readonly class SocketClient implements Client
{
    private int $id;

    public function __construct(
        private Socket $socket,
    ) {
        $this->id = createClientId();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }
}
