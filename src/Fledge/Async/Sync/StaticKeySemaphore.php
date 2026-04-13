<?php declare(strict_types=1);

namespace Fledge\Async\Sync;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final readonly class StaticKeySemaphore implements Mutex
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private KeyedSemaphore $semaphore,
        private string $key,
    ) {
    }

    public function acquire(): Lock
    {
        return $this->semaphore->acquire($this->key);
    }
}
