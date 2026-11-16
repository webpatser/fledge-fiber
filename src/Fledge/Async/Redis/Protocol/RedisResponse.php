<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Protocol;

/**
 * @psalm-type RedisValueType = int|string|list<mixed>|null
 *
 * Errors nested inside an array reply are carried as QueryException elements in their
 * position; only a top-level error reply throws on unwrap().
 */
interface RedisResponse
{
    /**
     * @return RedisValueType
     */
    public function unwrap(): int|string|array|null;
}
