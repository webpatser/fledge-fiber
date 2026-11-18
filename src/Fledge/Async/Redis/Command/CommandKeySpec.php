<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Command;

/**
 * Per-command key position tables shared by cluster slot routing and the key
 * prefixer. mapKeys() rewrites every routing-key position through a mapper;
 * extract() collects the routing keys for slot hashing; mapPrefixTargets()
 * additionally covers positions phpredis OPT_PREFIX rewrites that are not
 * routing keys (KEYS patterns, pub/sub channels).
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
        'CLUSTER', 'CONFIG', 'CLIENT', 'COMMAND', 'DEBUG',
        'SCRIPT', 'FUNCTION', 'SHUTDOWN', 'SAVE', 'BGSAVE',
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
    private const ALL_BUT_LAST = ['BLPOP', 'BRPOP', 'BZPOPMIN', 'BZPOPMAX'];

    /** Every argument after the first is a key: BITOP op dest src [src ...] */
    private const ALL_AFTER_FIRST = ['BITOP'];

    /** First two arguments are keys: RENAME src dst, SMOVE src dst member */
    private const TWO_KEYS = [
        'RENAME', 'RENAMENX', 'COPY', 'SMOVE', 'LMOVE', 'BLMOVE',
        'RPOPLPUSH', 'BRPOPLPUSH', 'GEOSEARCHSTORE', 'LCS', 'ZRANGESTORE',
    ];

    /** Numkeys first: LMPOP numkeys key1..keyN LEFT|RIGHT, ZUNION numkeys key1..keyN ... */
    private const NUMKEYS_AT_0 = ['LMPOP', 'ZMPOP', 'SINTERCARD', 'ZINTERCARD', 'ZUNION', 'ZINTER', 'ZDIFF'];

    /**
     * Numkeys second, first argument is not a key: EVAL script numkeys
     * key1..keyN arg..., BLMPOP timeout numkeys key1..keyN LEFT|RIGHT.
     */
    private const NUMKEYS_AT_1 = [
        'EVAL', 'EVALSHA', 'EVAL_RO', 'EVALSHA_RO', 'FCALL', 'FCALL_RO',
        'BLMPOP', 'BZMPOP',
    ];

    /** Store commands: destination, numkeys, key1..keyN, options... */
    private const STORE_NUMKEYS = ['ZUNIONSTORE', 'ZINTERSTORE', 'ZDIFFSTORE'];

    /** Stream reads: ... STREAMS key1..keyN id1..idN */
    private const STREAMS = ['XREAD', 'XREADGROUP'];

    /**
     * Container commands whose key follows the subcommand: XGROUP CREATE key
     * group ..., XINFO STREAM key, OBJECT ENCODING key, MEMORY USAGE key.
     * Subcommands not listed here (HELP, MEMORY STATS, ...) carry no key, so
     * the cluster link routes them to a random master.
     */
    private const SUBCOMMAND_KEY_AT_1 = [
        'XGROUP' => ['CREATE', 'SETID', 'DESTROY', 'CREATECONSUMER', 'DELCONSUMER'],
        'XINFO' => ['STREAM', 'GROUPS', 'CONSUMERS'],
        'OBJECT' => ['ENCODING', 'FREQ', 'IDLETIME', 'REFCOUNT'],
        'MEMORY' => ['USAGE'],
    ];

    /**
     * GEORADIUS family with optional STORE / STOREDIST destination keys,
     * mapped to the index where the options start. The _RO forms take no
     * STORE and use the default first-key rule.
     */
    private const GEORADIUS_OPTIONS_AT = ['GEORADIUS' => 5, 'GEORADIUSBYMEMBER' => 4];

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

        if (\in_array($upper, self::NUMKEYS_AT_0, true)) {
            return self::mapNumKeysRange($args, $mapper, numKeysAt: 0, includeFirst: false);
        }

        if (\in_array($upper, self::NUMKEYS_AT_1, true)) {
            return self::mapNumKeysRange($args, $mapper, numKeysAt: 1, includeFirst: false);
        }

        if (\in_array($upper, self::STORE_NUMKEYS, true)) {
            return self::mapNumKeysRange($args, $mapper, numKeysAt: 1, includeFirst: true);
        }

        if (\in_array($upper, self::ALL_AFTER_FIRST, true)) {
            return self::mapIndices($args, $mapper, static fn (int $i) => $i > 0);
        }

        if (\in_array($upper, self::STREAMS, true)) {
            return self::mapStreamsKeys($args, $mapper, group: $upper === 'XREADGROUP');
        }

        if (isset(self::SUBCOMMAND_KEY_AT_1[$upper])) {
            $subcommand = isset($args[0]) ? \strtoupper((string) $args[0]) : '';

            return \in_array($subcommand, self::SUBCOMMAND_KEY_AT_1[$upper], true)
                ? self::mapIndex($args, 1, $mapper)
                : $args;
        }

        if (isset(self::GEORADIUS_OPTIONS_AT[$upper])) {
            return self::mapGeoRadiusKeys($args, $mapper, self::GEORADIUS_OPTIONS_AT[$upper]);
        }

        if ($upper === 'MIGRATE') {
            return self::mapMigrateKeys($args, $mapper);
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
     * Numkeys shapes: [numkeys, key1..keyN, rest...] for LMPOP/ZUNION and
     * friends, [first, numkeys, key1..keyN, rest...] for EVAL/FCALL,
     * BLMPOP/BZMPOP and Z*STORE. When $includeFirst is set the first argument
     * (the store destination) is a key as well.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapNumKeysRange(array $args, \Closure $mapper, int $numKeysAt, bool $includeFirst): array
    {
        if (!\array_key_exists($numKeysAt, $args) || !\is_numeric($args[$numKeysAt])) {
            return $args;
        }

        if ($includeFirst) {
            $args[0] = $mapper($args[0]);
        }

        $start = $numKeysAt + 1;
        $numKeys = (int) $args[$numKeysAt];
        $end = \min($start + $numKeys, \count($args));

        for ($i = $start; $i < $end; $i++) {
            $args[$i] = $mapper($args[$i]);
        }

        return $args;
    }

    /**
     * XREAD [COUNT n] [BLOCK ms] STREAMS key1..keyN id1..idN and
     * XREADGROUP GROUP group consumer [COUNT n] [BLOCK ms] [NOACK] STREAMS ...
     * The fixed GROUP arguments and the option values are skipped before the
     * STREAMS keyword is matched, so a group, consumer or option value named
     * "streams" is not mistaken for it.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapStreamsKeys(array $args, \Closure $mapper, bool $group): array
    {
        $streamsAt = null;
        $count = \count($args);
        $i = 0;

        if ($group && isset($args[0]) && \strcasecmp((string) $args[0], 'GROUP') === 0) {
            $i = 3;
        }

        while ($i < $count) {
            $token = \strtoupper((string) $args[$i]);

            if ($token === 'STREAMS') {
                $streamsAt = $i;

                break;
            }

            // COUNT and BLOCK carry a value; NOACK and unknown flags do not.
            $i += ($token === 'COUNT' || $token === 'BLOCK') ? 2 : 1;
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
     * GEORADIUS key lon lat radius unit [options] and GEORADIUSBYMEMBER key
     * member radius unit [options]: the source key plus the value of every
     * STORE / STOREDIST option. Options are walked from $optionsAt so a
     * member named "store" is not mistaken for the option.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapGeoRadiusKeys(array $args, \Closure $mapper, int $optionsAt): array
    {
        $args = self::mapIndex($args, 0, $mapper);
        $count = \count($args);

        for ($i = $optionsAt; $i < $count; $i++) {
            $token = \strtoupper((string) $args[$i]);

            if ($token === 'COUNT') {
                $i++;
            } elseif (($token === 'STORE' || $token === 'STOREDIST') && $i + 1 < $count) {
                $args[$i + 1] = $mapper($args[$i + 1]);
                $i++;
            }
        }

        return $args;
    }

    /**
     * MIGRATE host port key|"" db timeout [COPY] [REPLACE] [AUTH pw]
     * [AUTH2 user pw] [KEYS key...]: the key at index 2, or, when that is
     * the empty string, every argument after the KEYS option. The host is
     * never a key.
     *
     * @param  list<int|string|float>  $args
     * @return list<int|string|float>
     */
    private static function mapMigrateKeys(array $args, \Closure $mapper): array
    {
        if (!\array_key_exists(2, $args)) {
            return $args;
        }

        if ((string) $args[2] !== '') {
            return self::mapIndex($args, 2, $mapper);
        }

        $count = \count($args);

        for ($i = 5; $i < $count; $i++) {
            $token = \strtoupper((string) $args[$i]);

            if ($token === 'AUTH') {
                $i++;
            } elseif ($token === 'AUTH2') {
                $i += 2;
            } elseif ($token === 'KEYS') {
                return self::mapIndices($args, $mapper, static fn (int $j) => $j > $i);
            }
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
