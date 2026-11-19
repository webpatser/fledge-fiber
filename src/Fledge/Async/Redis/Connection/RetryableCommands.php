<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

/**
 * The commands that may be safely retried or resent after a reconnect,
 * ported from Illuminate\Redis\Connections\PhpRedisConnection: read-only or
 * idempotent commands, plus SET without expiration or flags.
 *
 * DEL is idempotent in effect (the key is gone either way), so resending it
 * after a lost connection is safe; only its reply may differ, a resent DEL
 * reports 0 for a key the first attempt already removed.
 */
final class RetryableCommands
{
    private const array RETRYABLE = [
        'bitcount',
        'bitpos',
        'dbsize',
        'del',
        'dump',
        'exists',
        'geodist',
        'geohash',
        'geopos',
        'geosearch',
        'get',
        'getbit',
        'getrange',
        'hexists',
        'hget',
        'hgetall',
        'hkeys',
        'hlen',
        'hmget',
        'hmset',
        'hstrlen',
        'hvals',
        'keys',
        'lindex',
        'llen',
        'lpos',
        'lrange',
        'mget',
        'mset',
        'ping',
        'pttl',
        'randomkey',
        'scard',
        'sdiff',
        'sinter',
        'sismember',
        'smembers',
        'smismember',
        'srandmember',
        'strlen',
        'sunion',
        'time',
        'ttl',
        'type',
        'xinfo',
        'xlen',
        'xpending',
        'xrange',
        'xrevrange',
        'zcard',
        'zcount',
        'zlexcount',
        'zmscore',
        'zrange',
        'zrank',
        'zrevrank',
        'zscore',
    ];

    /**
     * @param  list<int|string|float>  $params
     */
    public static function isRetryable(string $command, array $params): bool
    {
        $command = \strtolower($command);

        if ($command === 'set') {
            // SET key value is idempotent; SET with expiration or flags
            // (EX/PX/NX/XX/KEEPTTL/GET) is not safely repeatable.
            return !isset($params[2]);
        }

        return \in_array($command, self::RETRYABLE, true);
    }
}
