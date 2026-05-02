<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

final class CommandKeyExtractor
{
    /**
     * Commands that don't operate on a specific key. The cluster link routes
     * these to a caller-selected node (random master by default).
     */
    private const TOPOLOGY = [
        'PING', 'ECHO', 'AUTH', 'HELLO', 'SELECT', 'QUIT', 'RESET',
        'INFO', 'TIME', 'DBSIZE', 'LASTSAVE', 'WAIT', 'LATENCY',
        'CLUSTER', 'CONFIG', 'CLIENT', 'COMMAND', 'DEBUG', 'MEMORY',
        'SCRIPT', 'FUNCTION', 'OBJECT', 'SHUTDOWN', 'SAVE', 'BGSAVE',
        'BGREWRITEAOF', 'SLAVEOF', 'REPLICAOF', 'FAILOVER', 'ACL',
        'MONITOR', 'SUBSCRIBE', 'UNSUBSCRIBE', 'PSUBSCRIBE', 'PUNSUBSCRIBE',
        'PUBLISH', 'PUBSUB',
        // SCAN, KEYS, RANDOMKEY, FLUSHDB, FLUSHALL operate per-node and the
        // cluster connection class fans out across masters. They reach the link
        // already routed to a single node.
        'SCAN', 'KEYS', 'RANDOMKEY', 'FLUSHDB', 'FLUSHALL', 'SWAPDB',
        // Transaction control verbs route to whatever connection the cluster
        // link has pinned for the active MULTI session.
        'MULTI', 'EXEC', 'DISCARD', 'UNWATCH',
        // ASKING is sent by the link itself during ASK redirect handling.
        'ASKING', 'READONLY', 'READWRITE',
    ];

    /** All arguments are keys: MGET key key ... */
    private const ALL_ARGS = [
        'MGET', 'DEL', 'UNLINK', 'EXISTS', 'TOUCH', 'WATCH',
        'PFCOUNT', 'PFMERGE',
        'SUNION', 'SINTER', 'SDIFF',
        'SUNIONSTORE', 'SINTERSTORE', 'SDIFFSTORE',
    ];

    /** Keys at even indices (0, 2, 4, ...): MSET k v k v ... */
    private const EVEN_INDEXED = ['MSET', 'MSETNX'];

    /** All but the last argument are keys: BLPOP k [k ...] timeout */
    private const ALL_BUT_LAST = ['BLPOP', 'BRPOP', 'BZPOPMIN', 'BZPOPMAX', 'BLMPOP', 'BZMPOP'];

    /** First two arguments are keys: RENAME src dst, SMOVE src dst member */
    private const TWO_KEYS = [
        'RENAME', 'RENAMENX', 'COPY', 'SMOVE', 'LMOVE', 'BLMOVE',
        'RPOPLPUSH', 'BRPOPLPUSH', 'GEOSEARCHSTORE', 'LCS', 'SORT_RO', 'SUBSTR',
    ];

    /**
     * Returns the keys this command operates on, given the parameter list.
     * Returns null when the command is a topology command (no key routing).
     *
     * @param  list<int|string|float>  $parameters
     * @return list<string>|null
     */
    public static function extract(string $command, array $parameters): ?array
    {
        $upper = \strtoupper($command);

        if (\in_array($upper, self::TOPOLOGY, true)) {
            return null;
        }

        if ($upper === 'EVAL' || $upper === 'EVALSHA' || $upper === 'EVAL_RO' || $upper === 'EVALSHA_RO' || $upper === 'FCALL' || $upper === 'FCALL_RO') {
            return self::scriptKeys($parameters);
        }

        if ($upper === 'ZUNIONSTORE' || $upper === 'ZINTERSTORE' || $upper === 'ZDIFFSTORE') {
            return self::storeWithNumKeys($parameters);
        }

        if ($upper === 'XREAD' || $upper === 'XREADGROUP') {
            return self::streamsKeys($parameters);
        }

        if (\in_array($upper, self::ALL_ARGS, true)) {
            return self::stringify($parameters);
        }

        if (\in_array($upper, self::EVEN_INDEXED, true)) {
            return self::stringify(self::pickIndices($parameters, fn (int $i) => $i % 2 === 0));
        }

        if (\in_array($upper, self::ALL_BUT_LAST, true)) {
            return $parameters === [] ? [] : self::stringify(\array_slice($parameters, 0, -1));
        }

        if (\in_array($upper, self::TWO_KEYS, true)) {
            return $parameters === [] ? [] : self::stringify(\array_slice($parameters, 0, 2));
        }

        // Default: first argument is the key. Covers GET/SET/HSET/ZADD/etc.
        if ($parameters === []) {
            return [];
        }

        return [(string) $parameters[0]];
    }

    /**
     * @param  list<int|string|float>  $parameters
     * @return list<int|string|float>
     */
    private static function pickIndices(array $parameters, \Closure $predicate): array
    {
        $picked = [];

        foreach ($parameters as $i => $value) {
            if ($predicate($i)) {
                $picked[] = $value;
            }
        }

        return $picked;
    }

    /**
     * EVAL / EVALSHA / FCALL: parameters are [script-or-name, numkeys, key1, ..., keyN, arg1, ...].
     *
     * @param  list<int|string|float>  $parameters
     * @return list<string>
     */
    private static function scriptKeys(array $parameters): array
    {
        if (\count($parameters) < 2 || !\is_numeric($parameters[1])) {
            return [];
        }

        $numKeys = (int) $parameters[1];

        return self::stringify(\array_slice($parameters, 2, $numKeys));
    }

    /**
     * ZUNIONSTORE / ZINTERSTORE: destination, numkeys, key1, ..., keyN, [WEIGHTS ...] [AGGREGATE ...].
     *
     * @param  list<int|string|float>  $parameters
     * @return list<string>
     */
    private static function storeWithNumKeys(array $parameters): array
    {
        if (\count($parameters) < 2 || !\is_numeric($parameters[1])) {
            return [];
        }

        $numKeys = (int) $parameters[1];

        $keys = [(string) $parameters[0]];
        \array_push($keys, ...self::stringify(\array_slice($parameters, 2, $numKeys)));

        return $keys;
    }

    /**
     * XREAD / XREADGROUP: ... STREAMS key1 ... keyN id1 ... idN.
     *
     * @param  list<int|string|float>  $parameters
     * @return list<string>
     */
    private static function streamsKeys(array $parameters): array
    {
        $streamsAt = null;

        foreach ($parameters as $i => $value) {
            if (\is_string($value) && \strcasecmp($value, 'STREAMS') === 0) {
                $streamsAt = $i;

                break;
            }
        }

        if ($streamsAt === null) {
            return [];
        }

        $remainder = \array_slice($parameters, $streamsAt + 1);

        if ($remainder === [] || (\count($remainder) % 2) !== 0) {
            return [];
        }

        $half = \intdiv(\count($remainder), 2);

        return self::stringify(\array_slice($remainder, 0, $half));
    }

    /**
     * @param  list<int|string|float>  $parameters
     * @return list<string>
     */
    private static function stringify(array $parameters): array
    {
        return \array_values(\array_map(\strval(...), $parameters));
    }
}
