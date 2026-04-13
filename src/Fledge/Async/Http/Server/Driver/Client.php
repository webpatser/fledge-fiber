<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Closable;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;

interface Client extends Closable
{
    /**
     * Integer ID of this client.
     */
    public function getId(): int;

    /**
     * @return SocketAddress Remote client address.
     */
    public function getRemoteAddress(): SocketAddress;

    /**
     * @return SocketAddress Local server address.
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * If the client is encrypted a TlsInfo object is returned, otherwise null.
     */
    public function getTlsInfo(): ?TlsInfo;
}
