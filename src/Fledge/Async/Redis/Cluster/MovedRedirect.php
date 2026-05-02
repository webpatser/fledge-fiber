<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

use Fledge\Async\Redis\Protocol\RedisError;

final class MovedRedirect
{
    public function __construct(
        public readonly int $slot,
        public readonly string $host,
        public readonly int $port,
    ) {
    }

    public static function tryParse(RedisError $error): ?self
    {
        if ($error->getKind() !== 'MOVED') {
            return null;
        }

        return self::parseRedirect($error->getMessage(), self::class);
    }

    public function endpoint(): string
    {
        return $this->host.':'.$this->port;
    }

    /**
     * Parses a redirect message of the form "<KIND> <slot> <host>:<port>".
     *
     * @template T of MovedRedirect|AskRedirect
     *
     * @param  class-string<T>  $target
     * @return T|null
     */
    public static function parseRedirect(string $message, string $target): ?object
    {
        $parts = \explode(' ', $message, 3);

        if (\count($parts) !== 3) {
            return null;
        }

        if (!\ctype_digit($parts[1])) {
            return null;
        }

        $slot = (int) $parts[1];
        $endpoint = $parts[2];

        $colon = \strrpos($endpoint, ':');

        if ($colon === false) {
            return null;
        }

        $host = \substr($endpoint, 0, $colon);
        $port = \substr($endpoint, $colon + 1);

        if ($host === '' || !\ctype_digit($port)) {
            return null;
        }

        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        return new $target($slot, $host, (int) $port);
    }
}
