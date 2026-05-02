<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

use Fledge\Async\Redis\Protocol\RedisError;

final class AskRedirect
{
    public function __construct(
        public readonly int $slot,
        public readonly string $host,
        public readonly int $port,
    ) {
    }

    public static function tryParse(RedisError $error): ?self
    {
        if ($error->getKind() !== 'ASK') {
            return null;
        }

        return MovedRedirect::parseRedirect($error->getMessage(), self::class);
    }

    public function endpoint(): string
    {
        return $this->host.':'.$this->port;
    }
}
