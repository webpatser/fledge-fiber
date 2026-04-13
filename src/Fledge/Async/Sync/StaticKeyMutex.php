<?php declare(strict_types=1);

namespace Fledge\Async\Sync;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final readonly class StaticKeyMutex implements Mutex
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private KeyedMutex $mutex,
        private string $key,
    ) {
    }

    public function acquire(): Lock
    {
        return $this->mutex->acquire($this->key);
    }
}
