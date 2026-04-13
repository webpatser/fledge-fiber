<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Redis\Protocol\RedisResponse;

interface RedisLink
{
    /**
     * @param array<int|float|string> $parameters
     */
    public function execute(string $command, array $parameters): RedisResponse;
}
