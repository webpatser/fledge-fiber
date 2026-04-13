<?php declare(strict_types=1);
/** @noinspection DuplicatedCode */

namespace Fledge\Async\Redis\Command;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\RedisClient;

final readonly class RedisHyperLogLog
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private RedisClient $client,
        private string $key,
    ) {
    }

    /**
     * @link https://redis.io/commands/pfadd
     */
    public function add(string $element, string ...$elements): bool
    {
        return (bool) $this->client->execute(
            'pfadd',
            $this->key,
            $element,
            ...$elements,
        );
    }

    /**
     * @link https://redis.io/commands/pfcount
     */
    public function count(): int
    {
        return $this->client->execute('pfcount', $this->key);
    }

    /**
     * @link https://redis.io/commands/pfmerge
     */
    public function storeUnion(string $sourceKey, string ...$sourceKeys): void
    {
        $this->client->execute(
            'pfmerge',
            $this->key,
            $sourceKey,
            ...$sourceKeys,
        );
    }
}
