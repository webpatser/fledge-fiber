<?php declare(strict_types=1);

namespace Fledge\Async\Sync;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final readonly class PrefixedKeyedSemaphore implements KeyedSemaphore
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private KeyedSemaphore $semaphore,
        private string $prefix
    ) {
    }

    public function acquire(string $key): Lock
    {
        return $this->semaphore->acquire($this->prefix . $key);
    }
}
