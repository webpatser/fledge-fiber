<?php declare(strict_types=1);

namespace Fledge\Async\Sync;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final readonly class PrefixedKeyedMutex implements KeyedMutex
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private KeyedMutex $mutex,
        private string $prefix
    ) {
    }

    public function acquire(string $key): Lock
    {
        return $this->mutex->acquire($this->prefix . $key);
    }
}
