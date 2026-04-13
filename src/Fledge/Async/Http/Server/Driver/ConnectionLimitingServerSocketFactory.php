<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\ResourceServerSocketFactory;
use Fledge\Async\Stream\ServerSocket;
use Fledge\Async\Stream\ServerSocketFactory;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Sync\Semaphore;

final readonly class ConnectionLimitingServerSocketFactory implements ServerSocketFactory
{
    public function __construct(
        private Semaphore $semaphore,
        private ServerSocketFactory $socketServerFactory = new ResourceServerSocketFactory(),
    ) {
    }

    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ServerSocket
    {
        return new ConnectionLimitingServerSocket(
            $this->socketServerFactory->listen($address, $bindContext),
            $this->semaphore,
        );
    }
}
