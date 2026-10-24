<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Connection\RetryableCommands;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException as AsyncRedisException;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Sync\LocalMutex;
use Fledge\Async\Sync\Lock;
use Closure;
use Fiber;
use Illuminate\Contracts\Redis\Connection as ConnectionContract;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class FledgeRedisConnection extends Connection implements ConnectionContract
{
    /**
     * The key prefix for this connection.
     */
    protected string $prefix;

    /**
     * Applies the key prefix with phpredis OPT_PREFIX semantics.
     */
    protected KeyPrefixer $keyPrefixer;

    /**
     * The connection creation callback.
     */
    protected $connector;

    /**
     * The connection configuration array.
     */
    protected array $config;

    /**
     * The Fledge Async Redis subscriber for pub/sub.
     */
    protected ?RedisSubscriber $subscriber;

    /**
     * Serializes MULTI ... EXEC/DISCARD blocks across fibers. All fibers share
     * one Redis link, so while a transaction is open every other fiber has to
     * wait, or its commands would be queued inside (and replied to from) the
     * other fiber's transaction.
     */
    protected ?LocalMutex $transactionMutex = null;

    /**
     * The lock held by the fiber that has a transaction open.
     */
    protected ?Lock $transactionLock = null;

    /**
     * The fiber (or main context marker) that has a transaction open.
     */
    protected ?object $transactionOwner = null;

    /**
     * Identity used for code running outside any fiber.
     */
    private static ?object $mainContext = null;

    /**
     * Create a new Fledge Async Redis connection.
     */
    public function __construct(
        RedisClient $client,
        ?RedisSubscriber $subscriber = null,
        ?callable $connector = null,
        array $config = [],
        string $prefix = '',
    ) {
        $this->client = $client;
        $this->subscriber = $subscriber;
        $this->connector = $connector;
        $this->config = $config;
        $this->prefix = $prefix;
        $this->keyPrefixer = new KeyPrefixer($prefix);
    }

    /**
     * The exception message fragments that mark a transient connection error
     * worth retrying, mirroring the upstream PhpRedisConnection list plus the
     * phpredis read timeout marker.
     */
    protected const array TRANSIENT_ERROR_MARKERS = [
        'went away', 'socket', 'connection', 'Connection', 'read error on connection',
    ];

    /**
     * Run a command against the Redis database, mirroring the upstream
     * PhpRedisConnection retry loop: safely retryable commands get one free
     * retry, command_retries raises the bound for every command, and the
     * client is rebuilt between attempts on transient connection errors.
     */
    public function command($method, array $parameters = [])
    {
        $retries = max(
            RetryableCommands::isRetryable($method, $parameters) ? 1 : 0,
            (int) ($this->config['command_retries'] ?? 0),
        );

        while (true) {
            $start = microtime(true);

            try {
                $result = $this->executeCommand($method, $parameters);
            } catch (Throwable $e) {
                $this->events?->dispatch(new CommandFailed(
                    $method, $this->parseParametersForEvent($parameters), $e, $this
                ));

                // A failure other than a Redis error reply (connection lost, read
                // timeout, client rebuilt) leaves the server-side MULTI state
                // unknown and the caller will likely never reach exec(): give up
                // the transaction so other fibers do not wait on it forever, and
                // never retry a queued command outside its MULTI.
                if (! $e instanceof QueryException && $this->ownsTransaction()) {
                    $this->abortTransaction();

                    throw $e;
                }

                $transient = $e instanceof AsyncRedisException
                    && Str::contains($e->getMessage(), static::TRANSIENT_ERROR_MARKERS);

                if ($transient && $this->connector) {
                    $this->client = call_user_func($this->connector);
                }

                if (! $transient || $retries-- === 0) {
                    throw $e;
                }

                continue;
            }

            $time = round((microtime(true) - $start) * 1000, 2);

            $this->events?->dispatch(new CommandExecuted(
                $method, $this->parseParametersForEvent($parameters), $time, $this
            ));

            return $result;
        }
    }

    /**
     * Execute a Redis command via Fledge Async's raw execute method.
     */
    protected function executeCommand(string $method, array $parameters): mixed
    {
        $command = strtoupper($method);
        $args = $this->keyPrefixer->apply($command, $this->flattenParameters($parameters));

        $this->waitForTransactionGate();

        return $this->client->execute($command, ...$args);
    }

    /**
     * Flatten nested arrays and cast all values to string/int/float for Fledge Async.
     */
    protected function flattenParameters(array $parameters): array
    {
        $flat = [];

        foreach ($parameters as $param) {
            if (is_array($param)) {
                foreach ($param as $key => $value) {
                    if (is_string($key)) {
                        $flat[] = $key;
                    }
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            if ($v !== null) {
                                $flat[] = $v;
                            }
                        }
                    } elseif ($value !== null) {
                        $flat[] = $value;
                    }
                }
            } elseif ($param !== null) {
                $flat[] = $param;
            }
        }

        return array_map(fn ($v) => is_bool($v) ? (int) $v : $v, $flat);
    }

    /**
     * Returns the value of the given key.
     */
    public function get($key)
    {
        return $this->command('get', [$key]);
    }

    /**
     * Get the values of all the given keys.
     */
    public function mget(array $keys)
    {
        return $this->command('mget', $keys);
    }

    /**
     * Set the string value in the argument as the value of the key.
     */
    public function set($key, $value, $expireResolution = null, $expireTTL = null, $flag = null)
    {
        $args = [$key, $value];

        if ($flag) {
            $args[] = $flag;
        }

        if ($expireResolution && $expireTTL !== null) {
            $args[] = $expireResolution;
            $args[] = $expireTTL;
        }

        return $this->command('set', $args);
    }

    /**
     * Set the given key if it doesn't exist.
     */
    public function setnx($key, $value)
    {
        return (int) $this->command('setnx', [$key, $value]);
    }

    /**
     * Set the string value with an expiration time.
     */
    public function setex($key, $seconds, $value)
    {
        return $this->command('setex', [$key, $seconds, $value]);
    }

    /**
     * Get the value of the given hash fields.
     */
    public function hmget($key, ...$dictionary)
    {
        if (count($dictionary) === 1) {
            $dictionary = $dictionary[0];
        }

        $result = $this->command('hmget', [$key, ...(array) $dictionary]);

        return is_array($result) ? array_values($result) : $result;
    }

    /**
     * Set the given hash fields to their respective values.
     */
    public function hmset($key, ...$dictionary)
    {
        if (count($dictionary) === 1) {
            $dictionary = $dictionary[0];
        } else {
            $input = new Collection($dictionary);
            $dictionary = $input->nth(2)->combine($input->nth(2, 1))->toArray();
        }

        $args = [$key];

        foreach ($dictionary as $field => $value) {
            $args[] = $field;
            $args[] = $value;
        }

        return $this->command('hmset', $args);
    }

    /**
     * Set the given hash field if it doesn't exist.
     */
    public function hsetnx($hash, $key, $value)
    {
        return (int) $this->command('hsetnx', [$hash, $key, $value]);
    }

    /**
     * Removes the first count occurrences of the value element from the list.
     */
    public function lrem($key, $count, $value)
    {
        return $this->command('lrem', [$key, $count, $value]);
    }

    /**
     * Removes and returns the first element of the list stored at key.
     */
    public function blpop(...$arguments)
    {
        $result = $this->command('blpop', $arguments);

        return empty($result) ? null : $result;
    }

    /**
     * Removes and returns the last element of the list stored at key.
     */
    public function brpop(...$arguments)
    {
        $result = $this->command('brpop', $arguments);

        return empty($result) ? null : $result;
    }

    /**
     * Removes and returns a random element from the set value at key.
     */
    public function spop($key, $count = 1)
    {
        return $this->command('spop', func_get_args());
    }

    /**
     * Add one or more members to a sorted set or update its score if it already exists.
     */
    public function zadd($key, ...$dictionary)
    {
        if (is_array(end($dictionary))) {
            foreach (array_pop($dictionary) as $member => $score) {
                $dictionary[] = $score;
                $dictionary[] = $member;
            }
        }

        $options = [];

        foreach (array_slice($dictionary, 0, 3) as $i => $value) {
            if (in_array($value, ['nx', 'xx', 'ch', 'incr', 'gt', 'lt', 'NX', 'XX', 'CH', 'INCR', 'GT', 'LT'], true)) {
                $options[] = $value;
                unset($dictionary[$i]);
            }
        }

        $args = [$key, ...$options, ...array_values($dictionary)];

        return $this->command('zadd', $args);
    }

    /**
     * Return elements with score between $min and $max.
     */
    public function zrangebyscore($key, $min, $max, $options = [])
    {
        $args = [$key, $min, $max];

        if (isset($options['withscores'])) {
            $args[] = 'WITHSCORES';
        }

        if (isset($options['limit'])) {
            $limit = $options['limit'];
            if (! array_is_list($limit)) {
                $limit = [$limit['offset'], $limit['count']];
            }
            $args[] = 'LIMIT';
            $args[] = $limit[0];
            $args[] = $limit[1];
        }

        return $this->command('zrangebyscore', $args);
    }

    /**
     * Return elements with score between $min and $max in reverse order.
     */
    public function zrevrangebyscore($key, $min, $max, $options = [])
    {
        $args = [$key, $min, $max];

        if (isset($options['withscores'])) {
            $args[] = 'WITHSCORES';
        }

        if (isset($options['limit'])) {
            $limit = $options['limit'];
            if (! array_is_list($limit)) {
                $limit = [$limit['offset'], $limit['count']];
            }
            $args[] = 'LIMIT';
            $args[] = $limit[0];
            $args[] = $limit[1];
        }

        return $this->command('zrevrangebyscore', $args);
    }

    /**
     * Find the intersection between sets and store in a new set.
     */
    public function zinterstore($output, $keys, $options = [])
    {
        $args = [$output, count($keys), ...$keys];

        if (! empty($options['weights'])) {
            $args[] = 'WEIGHTS';
            array_push($args, ...$options['weights']);
        }

        $args[] = 'AGGREGATE';
        $args[] = strtoupper($options['aggregate'] ?? 'sum');

        return $this->command('zinterstore', $args);
    }

    /**
     * Find the union between sets and store in a new set.
     */
    public function zunionstore($output, $keys, $options = [])
    {
        $args = [$output, count($keys), ...$keys];

        if (! empty($options['weights'])) {
            $args[] = 'WEIGHTS';
            array_push($args, ...$options['weights']);
        }

        $args[] = 'AGGREGATE';
        $args[] = strtoupper($options['aggregate'] ?? 'sum');

        return $this->command('zunionstore', $args);
    }

    /**
     * Scans all keys based on options.
     */
    public function scan($cursor, $options = [])
    {
        $args = [$cursor];

        if (isset($options['match'])) {
            $args[] = 'MATCH';
            $args[] = $options['match'];
        }

        if (isset($options['count'])) {
            $args[] = 'COUNT';
            $args[] = $options['count'];
        }

        $result = $this->command('scan', $args);

        if (! is_array($result)) {
            return false;
        }

        $newCursor = (string) $result[0];
        $keys = $result[1] ?? [];

        return [$newCursor, $keys];
    }

    /**
     * Scans the given set for all values based on options.
     */
    public function zscan($key, $cursor, $options = [])
    {
        $args = [$key, $cursor];

        if (isset($options['match'])) {
            $args[] = 'MATCH';
            $args[] = $options['match'];
        }

        if (isset($options['count'])) {
            $args[] = 'COUNT';
            $args[] = $options['count'];
        }

        $result = $this->command('zscan', $args);

        if (! is_array($result)) {
            return false;
        }

        $newCursor = (string) $result[0];
        $entries = $this->pairsToAssociative($result[1] ?? []);

        return [$newCursor, $entries];
    }

    /**
     * Scans the given hash for all values based on options.
     */
    public function hscan($key, $cursor, $options = [])
    {
        $args = [$key, $cursor];

        if (isset($options['match'])) {
            $args[] = 'MATCH';
            $args[] = $options['match'];
        }

        if (isset($options['count'])) {
            $args[] = 'COUNT';
            $args[] = $options['count'];
        }

        $result = $this->command('hscan', $args);

        if (! is_array($result)) {
            return false;
        }

        $newCursor = (string) $result[0];
        $entries = $this->pairsToAssociative($result[1] ?? []);

        return [$newCursor, $entries];
    }

    /**
     * Scans the given set for all values based on options.
     */
    public function sscan($key, $cursor, $options = [])
    {
        $args = [$key, $cursor];

        if (isset($options['match'])) {
            $args[] = 'MATCH';
            $args[] = $options['match'];
        }

        if (isset($options['count'])) {
            $args[] = 'COUNT';
            $args[] = $options['count'];
        }

        $result = $this->command('sscan', $args);

        if (! is_array($result)) {
            return false;
        }

        $newCursor = (string) $result[0];
        $members = $result[1] ?? [];

        return [$newCursor, $members];
    }

    /**
     * Convert a flat [key, value, key, value, ...] array to an associative array.
     */
    protected function pairsToAssociative(array $flat): array
    {
        $result = [];

        for ($i = 0; $i < count($flat); $i += 2) {
            if (isset($flat[$i + 1])) {
                $result[$flat[$i]] = $flat[$i + 1];
            }
        }

        return $result;
    }

    /**
     * Execute commands in a pipeline.
     *
     * Fledge Async Redis implicitly pipelines commands via the event loop. When called
     * with a callback, we simply execute it — the event loop batches commands
     * on the socket naturally. For the no-callback case, return a pipeline
     * accumulator that dispatches commands concurrently on exec().
     */
    public function pipeline(?callable $callback = null)
    {
        if (is_null($callback)) {
            return new FledgeRedisPipeline($this);
        }

        return $callback($this) ?? [];
    }

    /**
     * Execute commands in a transaction.
     *
     * Without a callback this behaves like multi(): the caller must finish
     * with exec() or discard(), which releases the transaction lock.
     */
    public function transaction(?callable $callback = null)
    {
        $this->beginTransaction();

        if (is_null($callback)) {
            return $this;
        }

        try {
            $callback($this);
        } catch (Throwable $e) {
            $this->endTransaction('DISCARD');

            throw $e;
        }

        return $this->endTransaction('EXEC');
    }

    /**
     * Enter MULTI mode.
     */
    public function multi()
    {
        $this->beginTransaction();

        return $this;
    }

    /**
     * Execute all commands issued after MULTI.
     */
    public function exec()
    {
        return $this->endTransaction('EXEC');
    }

    /**
     * Discard all commands issued after MULTI.
     */
    public function discard()
    {
        return $this->endTransaction('DISCARD');
    }

    /**
     * Whether the current fiber has a MULTI transaction open on this connection.
     */
    public function ownsTransaction(): bool
    {
        return $this->transactionOwner !== null
            && $this->transactionOwner === self::currentContext();
    }

    /**
     * Take the transaction lock (waiting for any other fiber's transaction to
     * finish) and send MULTI. A nested MULTI from the owning fiber is passed
     * through so Redis reports it as usual.
     */
    protected function beginTransaction(): void
    {
        if ($this->ownsTransaction()) {
            $this->client->execute('MULTI');

            return;
        }

        $lock = $this->transactionMutex()->acquire();
        $this->transactionLock = $lock;
        $this->transactionOwner = self::currentContext();

        try {
            $this->client->execute('MULTI');
        } catch (Throwable $e) {
            $this->releaseTransaction();

            throw $e;
        }
    }

    /**
     * Send EXEC or DISCARD and always release the transaction lock held by
     * the current fiber, also when the command fails.
     */
    protected function endTransaction(string $command): mixed
    {
        if (! $this->ownsTransaction()) {
            // Not in a transaction from this fiber: let Redis answer the
            // stray EXEC/DISCARD, but never inside another fiber's MULTI.
            $this->waitForTransactionGate();

            return $this->client->execute($command);
        }

        try {
            return $this->client->execute($command);
        } finally {
            $this->releaseTransaction();
        }
    }

    /**
     * Best-effort DISCARD after a failed command, then release the lock. On a
     * dropped connection the DISCARD runs on the fresh connection and Redis
     * answers "DISCARD without MULTI", which is ignored.
     */
    protected function abortTransaction(): void
    {
        try {
            $this->client->execute('DISCARD');
        } catch (Throwable) {
            // The transaction is gone either way.
        } finally {
            $this->releaseTransaction();
        }
    }

    /**
     * Drop transaction ownership and wake the next waiting fiber.
     */
    protected function releaseTransaction(): void
    {
        $lock = $this->transactionLock;

        $this->transactionLock = null;
        $this->transactionOwner = null;

        $lock?->release();
    }

    /**
     * Block the current fiber while another fiber has a transaction open.
     *
     * The link enqueues synchronously, so once this returns the following
     * client call is ordered before any later MULTI.
     */
    protected function waitForTransactionGate(): void
    {
        while ($this->transactionOwner !== null && ! $this->ownsTransaction()) {
            $this->transactionMutex()->acquire()->release();
        }
    }

    /**
     * Lazily create the transaction mutex.
     */
    protected function transactionMutex(): LocalMutex
    {
        return $this->transactionMutex ??= new LocalMutex;
    }

    /**
     * Identity of the running fiber, or a shared marker for the main context.
     */
    private static function currentContext(): object
    {
        return Fiber::getCurrent() ?? (self::$mainContext ??= new \stdClass);
    }

    /**
     * Evaluate a Lua script serverside.
     *
     * Fledge Async Redis's eval uses EVALSHA with SHA1 caching and NOSCRIPT fallback.
     */
    public function eval($script, $numberOfKeys, ...$arguments)
    {
        $keys = $this->keyPrefixer->prefixKeys(array_slice($arguments, 0, $numberOfKeys));
        $args = array_slice($arguments, $numberOfKeys);

        $start = microtime(true);

        try {
            $result = $this->evalWithFallback((string) $script, $keys, $args);
        } catch (Throwable $e) {
            $this->events?->dispatch(new CommandFailed(
                'eval', $this->parseParametersForEvent([$script, $numberOfKeys, ...$arguments]), $e, $this
            ));

            if (! $e instanceof QueryException && $this->ownsTransaction()) {
                $this->abortTransaction();
            }

            throw $e;
        }

        $time = round((microtime(true) - $start) * 1000, 2);

        $this->events?->dispatch(new CommandExecuted(
            'eval', $this->parseParametersForEvent([$script, $numberOfKeys, ...$arguments]), $time, $this
        ));

        return $result;
    }

    /**
     * EVALSHA with an EVAL fallback on NOSCRIPT. Done here rather than via
     * RedisClient::eval() so the transaction gate is checked before each of
     * the two sends: the fallback is issued after a suspension, by which time
     * another fiber may have opened a MULTI.
     */
    protected function evalWithFallback(string $script, array $keys, array $args): mixed
    {
        $count = count($keys);

        try {
            $this->waitForTransactionGate();

            return $this->client->execute('evalsha', sha1($script), $count, ...$keys, ...$this->flattenParameters($args));
        } catch (QueryException $e) {
            if (! str_starts_with($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }
        }

        $this->waitForTransactionGate();

        return $this->client->execute('eval', $script, $count, ...$keys, ...$this->flattenParameters($args));
    }

    /**
     * Evaluate a LUA script serverside, from the SHA1 hash of the script instead of the script itself.
     */
    public function evalsha($script, $numkeys, ...$arguments)
    {
        return $this->eval($script, $numkeys, ...$arguments);
    }

    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe($channels, Closure $callback)
    {
        if (! $this->subscriber) {
            throw new \RuntimeException('No RedisSubscriber available for pub/sub.');
        }

        foreach ((array) $channels as $channel) {
            // phpredis OPT_PREFIX prefixes channel names, and subscribe
            // callbacks receive the prefixed name as sent by the server.
            $channel = $this->keyPrefixer->prefixChannel((string) $channel);

            $subscription = $this->subscriber->subscribe($channel);

            foreach ($subscription as $message) {
                $callback($message, $channel);
            }
        }
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe($channels, Closure $callback)
    {
        if (! $this->subscriber) {
            throw new \RuntimeException('No RedisSubscriber available for pub/sub.');
        }

        foreach ((array) $channels as $pattern) {
            $pattern = $this->keyPrefixer->prefixChannel((string) $pattern);

            $subscription = $this->subscriber->subscribeToPattern($pattern);

            foreach ($subscription as $message) {
                $callback($message, $pattern);
            }
        }
    }

    /**
     * Subscribe to a set of given channels for messages.
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe')
    {
        //
    }

    /**
     * Flush the selected Redis database.
     */
    public function flushdb()
    {
        $arguments = func_get_args();

        if (strtoupper((string) ($arguments[0] ?? null)) === 'ASYNC') {
            return $this->command('flushdb', ['ASYNC']);
        }

        return $this->command('flushdb', []);
    }

    /**
     * Execute a raw command.
     */
    public function executeRaw(array $parameters)
    {
        $command = array_shift($parameters);

        $this->waitForTransactionGate();

        return $this->client->execute($command, ...$parameters);
    }

    /**
     * Get the key prefix for this connection.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Prefix the given key.
     */
    public function _prefix($key)
    {
        return $this->prefix.$key;
    }

    /**
     * Disconnects from the Redis instance.
     */
    public function disconnect()
    {
        try {
            $this->client->quit();
        } catch (Throwable) {
            // Connection may already be closed
        }
    }

    /**
     * Pass other method calls down to the underlying client.
     */
    public function __call($method, $parameters)
    {
        return $this->command(strtolower($method), $parameters);
    }
}
