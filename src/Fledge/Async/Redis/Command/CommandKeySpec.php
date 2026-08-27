<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Command;

/**
 * Per-command key position tables shared by cluster slot routing and the key
 * prefixer. mapKeys() rewrites every routing-key position through a mapper;
 * extract() collects the routing keys for slot hashing; mapPrefixTargets()
 * additionally covers positions phpredis OPT_PREFIX rewrites that are not
 * routing keys (KEYS patterns, pub/sub channels, OBJECT subcommand keys).
 */
final class CommandKeySpec
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

    /** Script commands: script-or-name, numkeys, key1..keyN, arg... */
    private const NUMKEYS_AT_1 = ['EVAL', 'EVALSHA', 'EVAL_RO', 'EVALSHA_RO', 'FCALL', 'FCALL_RO'];

    /** Store commands: destination, numkeys, key1..keyN, options... */
    private const STORE_NUMKEYS = ['ZUNIONSTORE', 'ZINTERSTORE', 'ZDIFFSTORE'];

    /** Stream reads: ... STREAMS key1..keyN id1..idN */
    private const STREAMS = ['XREAD', 'XREADGROUP'];

    /**
     * Channel-carrying commands, consulted only by the key prefixer:
     * phpredis OPT_PREFIX rewrites pub/sub channel names exactly like keys
     * (verified against phpredis 6.3.0: SUBSCRIBE and PUBLISH both operate
     * on the prefixed channel, and subscribe callbacks receive the
     * prefixed name).
     */
    private const ALL_CHANNELS = ['SUBSCRIBE', 'UNSUBSCRIBE', 'PSUBSCRIBE', 'PUNSUBSCRIBE'];

    /** Channel in the first argument: PUBLISH channel message */
    private const FIRST_CHANNEL = ['PUBLISH', 'SPUBLISH'];

    public static function isTopology(string $command): bool
    {
        return \in_array(\strtoupper($command), self::TOPOLOGY, true);
    }

    /**
     * Returns the keys this command operates on, given the parameter list.
     * Returns null when the command is a topology command (no key routing).
     *
     * @param  list<int|string|float>  $parameters
     * @return list<string>|null
     */
    public static function extract(string $command, array $parameters): ?array
    {
        if (self::isTopology($command)) {
            return null;
        }

        $keys = [];

        self::mapKeys($command, $parameters, static function (int|string|float $key) use (&$keys) {
            $keys[] = (string) $key;

            return $key;
        });

        return $keys;
    }

    /**
     * Applies $mapper to every routing-key position and returns the full
     * argument list. Topology commands come back untouched.
     *
     * @param  list<int|string|float>  $args
     * @param  \Closure(int|string|float): (int|string|float)  $mapper
     * @return list<int|string|float>
     */
    public static function mapKeys(string $command, array $args, \Closure $mapper): array
    {
        $upper = \strtoupper($command);

        if (\in_array($upper, self::TOPOLOGY, true)) {
            return $args;
        }

        if (\in_array($upper, self::NUMKEYS_AT_1, true)) {
            return self::mapNumKeysRange($args, $mapper, includeFirst: false);
        }

        if (\in_array($upper, self::STORE_NUMKEYS, true)) {
            return self::mapNumKeysRange($args, $mapper, includeFirst: true);
        }

        if (\in_array($upper, self::STREAMS, true)) {
            return self::mapStreamsKeys($args, $mapper);
        }

        if (\in_array($upper, self::ALL_ARGS, true)) {
            return \array_map($mapper, $args);
        }

        if (\in_array($upper, self::EVEN_INDEXED, true)) {
            return self::mapIndices($args, $mapper, static fn (int $i) => $i % 2 === 0);
        }

        if (\in_array($upper, self::ALL_BUT_LAST, true)) {
            $last = \count($args) - 1;

            return self::mapIndices($args, $mapper, static fn (int $i) => $i < $last);
        }

        if (\in_array($upper, self::TWO_KEYS, true)) {
            return self::mapIndices($args, $mapper, static fn (int $i) => $i < 2);
        }

        // Default: first argument is the key. Covers GET/SET/HSET/ZADD/etc.
        return self::mapIndex($args, 0, $mapper);
    }

    /**
     * Applies $mapper to channel positions. Returns null when the command
     * does not carry channels.
     *
     * @param  list<int|string|float>  $args
     * @param  \Closure(int|string|float): (int|string|float)  $mapper
     * @return list<int|string|float>|null
     */
    public static function mapChannels(string $command, array $args, \Closure $mapper): ?array
    {
        $upper = \strtoupper($command);

        if (\in_array($upper, self::ALL_CHANNELS, true)) {
            return \array_map($mapper, $args);
        }

        if (\in_array($upper, self::FIRST_CHANNEL, true)) {
            return self::mapIndex($args, 0, $mapper);
        }

        return null;
    }

    /**
     * Applies $mapper to every position phpredis OPT_PREFIX would rewrite,
     * mirroring phpredis semantics exactly (verified against phpredis 6.3.0):
     *
     * - KEYS: the pattern is prefixed; returned keys are not stripped
     * - SCAN: the MATCH value is prefixed only when $scanPrefix is set
     *   (phpredis Redis::SCAN_PREFIX; the default leaves the pattern alone)
     * - HSCAN/SSCAN/ZSCAN: only the key is prefixed, MATCH targets
     *   fields/members and stays untouched
     * - OBJECT ENCODING/FREQ/IDLETIME/REFCOUNT: the subcommand key is prefixed
     * - SORT/SORT_RO: only the source key is prefixed; phpredis does not
     *   prefix the STORE destination or BY/GET patterns
     * - SUBSCRIBE/PSUBSCRIBE/UNSUBSCRIBE/PUNSUBSCRIBE/PUBLISH: channels are
     *   prefixed like keys
     * - everything else: the routing-key positions from mapKeys()
     *
     * @param  list<int|string|float>  $args
     * @param  \Closure(int|string|float): (int|string|float)  $mapper
     * @return list<int|string|float>
     */
    public static function mapPrefixTargets(
        string $command,
        array $args,
        \Closure $mapper,
        bool $scanPrefix = false,
    ): array {
        $upper = \strtoupper($command);

        if (($channels = self::mapChannels($upper, $args, $mapper)) !== null) {
            return $channels;
        }

        return match (true) {
            $upper === 'KEYS' => self::mapIndex($args, 0, $mapper),
            $upper === 'SCAN' => $scanPrefix ? self::mapAfterToken($args, 'MATCH', $mapper) : $args,
            $upper === 'HSCAN' || $upper === 'SSCAN' || $upper === 'ZSCAN',
            $upper === 'SORT' || $upper === 'SORT_RO',
            $upper === 'GETEX' => self::mapIndex($args, 0, $mapper),
            $upper === 'OBJECT' => \count($args) >= 2 ? self::mapIndex($args, 1, $mapper) : $args,
            default => self::mapKeys($upper, $args, $mapper),
        };
    }

    /**
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapIndex(array $args, int $index, \Closure $mapper): array
    {
        if (\array_key_exists($index, $args)) {
            $args[$index] = $mapper($args[$index]);
        }

        return $args;
    }

    /**
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapIndices(array $args, \Closure $mapper, \Closure $predicate): array
    {
        foreach ($args as $i => $value) {
            if ($predicate($i)) {
                $args[$i] = $mapper($value);
            }
        }

        return $args;
    }

    /**
     * EVAL/FCALL and Z*STORE shapes: [first, numkeys, key1..keyN, rest...].
     * When $includeFirst is set the first argument (the store destination)
     * is a key as well.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapNumKeysRange(array $args, \Closure $mapper, bool $includeFirst): array
    {
        if (\count($args) < 2 || !\is_numeric($args[1])) {
            return $args;
        }

        if ($includeFirst) {
            $args[0] = $mapper($args[0]);
        }

        $numKeys = (int) $args[1];
        $end = \min(2 + $numKeys, \count($args));

        for ($i = 2; $i < $end; $i++) {
            $args[$i] = $mapper($args[$i]);
        }

        return $args;
    }

    /**
     * XREAD / XREADGROUP: ... STREAMS key1..keyN id1..idN.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapStreamsKeys(array $args, \Closure $mapper): array
    {
        $streamsAt = null;

        foreach ($args as $i => $value) {
            if (\is_string($value) && \strcasecmp($value, 'STREAMS') === 0) {
                $streamsAt = $i;

                break;
            }
        }

        if ($streamsAt === null) {
            return $args;
        }

        $remainder = \count($args) - $streamsAt - 1;

        if ($remainder <= 0 || ($remainder % 2) !== 0) {
            return $args;
        }

        $half = \intdiv($remainder, 2);

        for ($i = $streamsAt + 1, $end = $streamsAt + 1 + $half; $i < $end; $i++) {
            $args[$i] = $mapper($args[$i]);
        }

        return $args;
    }

    /**
     * Maps the value directly following a token such as MATCH.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapAfterToken(array $args, string $token, \Closure $mapper): array
    {
        foreach ($args as $i => $value) {
            if (\is_string($value) && \strcasecmp($value, $token) === 0 && \array_key_exists($i + 1, $args)) {
                $args[$i + 1] = $mapper($args[$i + 1]);

                break;
            }
        }

        return $args;
    }
}
