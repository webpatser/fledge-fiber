<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Command\CommandKeySpec;

/**
 * Applies the connection key prefix to outgoing commands with phpredis
 * OPT_PREFIX semantics, using the per-command key positions from
 * CommandKeySpec. Applied above the link so cluster slot hashing sees the
 * prefixed keys. Results are never stripped, matching phpredis.
 */
final readonly class KeyPrefixer
{
    public function __construct(
        private string $prefix,
        private bool $scanPrefix = false,
    ) {
    }

    public function isActive(): bool
    {
        return $this->prefix !== '';
    }

    /**
     * Rewrites every prefixable position in a flattened wire argument list.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    public function apply(string $command, array $args): array
    {
        if ($this->prefix === '') {
            return $args;
        }

        return CommandKeySpec::mapPrefixTargets(
            $command,
            $args,
            fn (int|string|float $value): string => $this->prefix.$value,
            $this->scanPrefix,
        );
    }

    /**
     * Prefixes an explicit key list (the EVAL/EVALSHA KEYS argument).
     *
     * @param  list<int|string|float>  $keys
     * @return list<int|string|float>
     */
    public function prefixKeys(array $keys): array
    {
        if ($this->prefix === '') {
            return $keys;
        }

        return array_map(fn (int|string|float $key): string => $this->prefix.$key, $keys);
    }

    public function prefixChannel(string $channel): string
    {
        return $this->prefix.$channel;
    }
}
