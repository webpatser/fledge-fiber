<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\Cache\Cache;
use Fledge\Async\Cache\CacheException;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\Command\Option\SetOptions;
use Fledge\Async\Serialization\NativeSerializer;
use Fledge\Async\Serialization\Serializer;

/**
 * @template T
 * @implements Cache<T>
 */
final readonly class RedisCache implements Cache
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private RedisClient $redis,
        private Serializer $serializer = new NativeSerializer(),
    ) {
    }

    public function get(string $key): mixed
    {
        try {
            $data = $this->redis->get($key);
            if ($data === null) {
                return null;
            }

            return $this->serializer->unserialize($data);
        } catch (RedisException $e) {
            throw new CacheException("Fetching '$key' from cache failed", 0, $e);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null && $ttl < 0) {
            throw new \Error('Invalid TTL: ' . $ttl);
        }

        if ($ttl === 0) {
            return; // expires immediately
        }

        try {
            $options = new SetOptions;

            if ($ttl !== null) {
                $options = $options->withTtl($ttl);
            }

            $this->redis->set($key, $this->serializer->serialize($value), $options);
        } catch (RedisException $e) {
            throw new CacheException("Storing '{$key}' to cache failed", 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        try {
            return (bool) $this->redis->delete($key);
        } catch (RedisException $e) {
            throw new CacheException("Deleting '{$key}' from cache failed", 0, $e);
        }
    }
}
