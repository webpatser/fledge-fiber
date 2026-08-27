<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

use Fledge\Async\Redis\Command\CommandKeySpec;

/**
 * Thin wrapper around {@see CommandKeySpec} kept for the cluster link: the
 * per-command key position tables live in the spec so the key prefixer and
 * slot routing share one source of truth.
 */
final class CommandKeyExtractor
{
    /**
     * Returns the keys this command operates on, given the parameter list.
     * Returns null when the command is a topology command (no key routing).
     *
     * @param  list<int|string|float>  $parameters
     * @return list<string>|null
     */
    public static function extract(string $command, array $parameters): ?array
    {
        return CommandKeySpec::extract($command, $parameters);
    }
}
