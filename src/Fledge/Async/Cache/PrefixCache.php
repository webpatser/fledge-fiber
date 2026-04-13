<?php declare(strict_types=1);

namespace Fledge\Async\Cache;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * @template TValue
 * @implements Cache<TValue>
 */
final readonly class PrefixCache implements Cache
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private Cache $cache,
        private string $keyPrefix,
    ) {
    }

    /**
     * Gets the specified key prefix.
     */
    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($this->keyPrefix . $key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->cache->set($this->keyPrefix . $key, $value, $ttl);
    }

    public function delete(string $key): ?bool
    {
        return $this->cache->delete($this->keyPrefix . $key);
    }
}
