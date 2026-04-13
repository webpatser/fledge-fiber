<?php declare(strict_types=1);

namespace Fledge\Async\Sync;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final class LocalMutex implements Mutex
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalSemaphore $semaphore;

    public function __construct()
    {
        $this->semaphore = new LocalSemaphore(1);
    }

    public function acquire(): Lock
    {
        return $this->semaphore->acquire();
    }
}
