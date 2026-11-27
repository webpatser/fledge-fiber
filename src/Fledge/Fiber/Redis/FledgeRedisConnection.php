<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Connection\RetryableCommands;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException as AsyncRedisException;
use Fledge\Async\Queue;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\RedisSubscription;
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

use function Fledge\Async\async;

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
     * Idle connections for blocking commands, built from the connector.
     *
     * @var list<RedisClient>
     */
    protected array $blockingClients = [];

    /**
     * Blocking connections with a command in flight, by object id, so
     * disconnect() can drop them.
     *
     * @var array<int, RedisClient>
     */
    protected array $checkedOutBlockingClients = [];

    /**
     * The database chosen with SELECT at runtime (null: the configured one),
     * carried over to the blocking connections.
     */
    protected ?int $selectedDatabase = null;

    /**
     * The SELECTs queued inside this fiber's MULTI, by their position in the
     * EXEC reply; the last one EXEC ran without an error is applied.
     *
     * @var array<int, int>
     */
    protected array $pendingSelects = [];

    /**
     * Commands sent inside this fiber's MULTI so far, which gives the
     * position of each one's entry in the EXEC reply.
     */
    protected int $queuedCommands = 0;

    /**
     * Bumped by SELECT and disconnect(): a blocking connection taken out
     * under an older generation is closed when it returns.
     */
    protected int $blockingGeneration = 0;

    /**
     * Bumped by disconnect(): a blocking call it failed is not retried.
     */
    protected int $disconnects = 0;

    /**
     * Idle blocking connections kept for reuse; the rest are closed when
     * they return. A worker loop runs one blocking pop at a time, so 2 keeps
     * it (plus one overlapping call) free of reconnects while a burst of
     * concurrent calls cannot pin connections against maxclients.
     */
    public const int BLOCKING_IDLE_LIMIT = 2;

    /**
     * Commands that hold their connection until they return. XREAD and
     * XREADGROUP join them when called with BLOCK.
     */
    protected const array BLOCKING_COMMANDS = [
        'BLPOP', 'BRPOP', 'BRPOPLPUSH', 'BLMOVE', 'BLMPOP', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP',
    ];

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
        'went away', 'socket', 'connection', 'Connection', 'read error on connection', 'Broken pipe',
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
            $disconnects = $this->disconnects;

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

                // Redis rejected the command while queueing it (wrong arity,
                // unknown command): phpredis answers false and lets the
                // following EXEC report EXECABORT, so do the same.
                if ($e instanceof QueryException && $this->ownsTransaction()) {
                    return false;
                }

                $transient = $e instanceof AsyncRedisException
                    && Str::contains($e->getMessage(), static::TRANSIENT_ERROR_MARKERS);

                // A blocking command failed on its own connection, which has
                // been dropped already: the shared one is fine.
                if ($transient && $this->connector && ! $this->blocksConnection($method, $parameters)) {
                    $this->client = call_user_func($this->connector);

                    // The fresh client is on the configured database: restore
                    // the runtime one before the command is retried.
                    if ($this->selectedDatabase !== null) {
                        $this->client->execute('SELECT', $this->selectedDatabase);
                    }
                }

                // disconnect() dropped this blocking call on purpose (worker
                // shutdown): retrying it on a new connection would hang.
                if ($disconnects !== $this->disconnects && $this->blocksConnection($method, $parameters)) {
                    throw $e;
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

            return $this->queuedReply(static::toPhpRedisReply($result));
        }
    }

    /**
     * Inside this fiber's MULTI, phpredis answers every queued command with
     * the client itself so calls chain (multi()->incr()->exec()). Mirror that
     * by returning the connection for the QUEUED status reply.
     */
    protected function queuedReply(mixed $result): mixed
    {
        return $result === 'QUEUED' && $this->ownsTransaction() ? $this : $result;
    }

    /**
     * Execute a Redis command via Fledge Async's raw execute method.
     */
    protected function executeCommand(string $method, array $parameters): mixed
    {
        $command = strtoupper($method);
        $args = $this->keyPrefixer->apply($command, $this->flattenParameters($parameters));

        // A blocking command holds its socket until it returns, which would
        // queue every fiber sharing $this->client behind it. Like phpredis,
        // where it only blocks its own process, it gets a connection of its
        // own; no transaction gate, as another fiber's MULTI is not on it.
        if ($this->runsOnBlockingClient($command, $args)) {
            return $this->executeBlocking($command, $args);
        }

        $this->waitForTransactionGate();

        $result = $this->client->execute($command, ...$args);

        if ($this->ownsTransaction()) {
            $position = $this->queuedCommands++;

            if ($command === 'SELECT') {
                $this->pendingSelects[$position] = (int) $args[0];
            }
        } elseif ($command === 'SELECT') {
            $this->applySelectedDatabase((int) $args[0]);
        }

        return $result;
    }

    /**
     * Remember the runtime database and close the idle blocking connections,
     * which are still on the previous one.
     */
    protected function applySelectedDatabase(int $database): void
    {
        $this->selectedDatabase = $database;
        $this->blockingGeneration++;

        // The link records a plain SELECT itself, but not one EXEC ran.
        $this->client->rememberDatabase($database);

        $this->closeIdleBlockingClients();
    }

    /**
     * QUIT every idle blocking connection.
     */
    protected function closeIdleBlockingClients(): void
    {
        $clients = $this->blockingClients;
        $this->blockingClients = [];

        foreach ($clients as $client) {
            $this->closeBlockingClient($client);
        }
    }

    protected function closeBlockingClient(RedisClient $client): void
    {
        try {
            $client->quit();
        } catch (Throwable) {
            // Connection may already be closed
        }
    }

    /**
     * Keep a returning blocking connection for reuse, or close it when it
     * belongs to an older generation or the idle list is full.
     */
    protected function releaseBlockingClient(RedisClient $client, int $generation): void
    {
        if ($generation === $this->blockingGeneration && count($this->blockingClients) < static::BLOCKING_IDLE_LIMIT) {
            $this->blockingClients[] = $client;

            return;
        }

        $this->closeBlockingClient($client);
    }

    /**
     * Whether the command would run on a blocking connection of its own;
     * the pipeline runs such a batch in order instead of concurrently.
     */
    public function blocksConnection(string $method, array $parameters): bool
    {
        return $this->runsOnBlockingClient(strtoupper($method), $this->flattenParameters($parameters));
    }

    /**
     * Blocking commands leave the shared connection when a connector can
     * build another one. Inside this fiber's MULTI Redis only queues them
     * (and never blocks), so they stay with the transaction.
     */
    protected function runsOnBlockingClient(string $command, array $args): bool
    {
        if ($this->connector === null || $this->ownsTransaction()) {
            return false;
        }

        if (in_array($command, static::BLOCKING_COMMANDS, true)) {
            return true;
        }

        if ($command !== 'XREAD' && $command !== 'XREADGROUP') {
            return false;
        }

        // Options precede STREAMS; XREADGROUP opens with GROUP group consumer.
        for ($i = $command === 'XREADGROUP' ? 3 : 0, $count = count($args); $i < $count; $i++) {
            $token = strtoupper((string) $args[$i]);

            if ($token === 'STREAMS') {
                return false;
            }

            if ($token === 'BLOCK') {
                return true;
            }

            if ($token === 'COUNT') {
                $i++;
            }
        }

        return false;
    }

    /**
     * Run a blocking command on an idle blocking connection, or a new one.
     * It goes back to the idle list after a reply or a Redis error reply;
     * after any other failure (lost connection, read timeout) it is dropped,
     * as its state is unknown. One taken out before a later SELECT or
     * disconnect(), or beyond the idle limit, is closed on return.
     */
    protected function executeBlocking(string $command, array $args): mixed
    {
        $database = $this->selectedDatabase;
        $generation = $this->blockingGeneration;
        $client = array_pop($this->blockingClients);

        if ($client === null) {
            $client = call_user_func($this->connector);

            if ($database !== null) {
                $client->execute('SELECT', $database);
            }
        }

        $id = spl_object_id($client);
        $this->checkedOutBlockingClients[$id] = $client;

        try {
            $result = $client->execute($command, ...$args);
        } catch (QueryException $e) {
            unset($this->checkedOutBlockingClients[$id]);
            $this->releaseBlockingClient($client, $generation);

            throw $e;
        } catch (Throwable $e) {
            unset($this->checkedOutBlockingClients[$id]);

            throw $e;
        }

        unset($this->checkedOutBlockingClients[$id]);
        $this->releaseBlockingClient($client, $generation);

        return $result;
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
        $result = $this->command('setnx', [$key, $value]);

        return $result === $this ? $this : (int) $result;
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
        $result = $this->command('hsetnx', [$hash, $key, $value]);

        return $result === $this ? $this : (int) $result;
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
     * Read from one or more streams, with the phpredis signature: $streams
     * is [key => id]; a negative (or null) count or block is left out, and
     * BLOCK 0 blocks forever. With BLOCK the read runs on a blocking
     * connection of its own.
     */
    public function xread(array $streams, ?int $count = -1, ?int $block = -1)
    {
        $args = [];

        if ($count !== null && $count > -1) {
            array_push($args, 'COUNT', $count);
        }

        if ($block !== null && $block > -1) {
            array_push($args, 'BLOCK', $block);
        }

        return $this->streamReply($this->command('xread', $this->streamArguments($args, $streams)));
    }

    /**
     * Read from one or more streams as a consumer group member, with the
     * phpredis signature. Like phpredis, count and block are only sent when
     * passed (the declared defaults of 1 are never sent), null leaves them
     * out, and a negative value warns and answers false.
     */
    public function xreadgroup(string $group, string $consumer, array $streams, ?int $count = 1, ?int $block = 1)
    {
        $passed = func_num_args();
        $count = $passed > 3 ? $count : null;
        $block = $passed > 4 ? $block : null;

        if (($count ?? 0) < 0 || ($block ?? 0) < 0) {
            trigger_error('Negative values for COUNT or BLOCK are illegal.', E_USER_WARNING);

            return false;
        }

        $args = ['GROUP', $group, $consumer];

        if ($count !== null) {
            array_push($args, 'COUNT', $count);
        }

        if ($block !== null) {
            array_push($args, 'BLOCK', $block);
        }

        return $this->streamReply($this->command('xreadgroup', $this->streamArguments($args, $streams)));
    }

    /**
     * Append STREAMS key... id... to the options.
     */
    protected function streamArguments(array $options, array $streams): array
    {
        return [...$options, 'STREAMS', ...array_map(strval(...), array_keys($streams)), ...array_values($streams)];
    }

    /**
     * Shape a stream read reply as phpredis does: [stream => [id => [field
     * => value]]], and an empty array for a timeout or nothing to read.
     * Stream names stay as the server sent them (prefixed, as in phpredis).
     */
    protected function streamReply(mixed $reply): mixed
    {
        if ($reply === null) {
            return [];
        }

        if (! is_array($reply)) {
            return $reply;
        }

        $streams = [];

        // RESP2: [[name, [[id, [field, value, ...]], ...]], ...].
        foreach ($reply as [$name, $entries]) {
            $messages = [];

            foreach ((array) $entries as [$id, $fields]) {
                $messages[$id] = $this->pairsToAssociative((array) $fields);
            }

            $streams[$name] = $messages;
        }

        return $streams;
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

        if ($result === $this) {
            return $this;
        }

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

        if ($result === $this) {
            return $this;
        }

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

        if ($result === $this) {
            return $this;
        }

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

        if ($result === $this) {
            return $this;
        }

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
     * Fledge Async Redis implicitly pipelines commands via the event loop. With
     * a callback (the phpredis contract) the callback receives a pipeline
     * accumulator, and the reply array from its exec() is returned. Without a
     * callback the pipeline accumulator itself is returned, to be finished
     * with exec().
     */
    public function pipeline(?callable $callback = null)
    {
        $pipeline = new FledgeRedisPipeline($this);

        if (is_null($callback)) {
            return $pipeline;
        }

        $callback($pipeline);

        return $pipeline->exec();
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

            return static::toPhpRedisReply($this->client->execute($command));
        }

        $pendingSelects = $this->pendingSelects;

        try {
            $reply = $this->client->execute($command);
        } catch (QueryException $e) {
            // A command rejected while queueing makes Redis discard the
            // transaction; phpredis returns false from exec() for it.
            if ($command === 'EXEC' && str_starts_with($e->getMessage(), 'EXECABORT')) {
                return false;
            }

            throw $e;
        } finally {
            $this->releaseTransaction();
        }

        // A SELECT queued in this MULTI took effect only if EXEC ran it and
        // its own reply entry is not an error (SELECT 9999 is only checked
        // at EXEC); the last one that succeeded wins.
        if ($command === 'EXEC' && is_array($reply)) {
            foreach (array_reverse($pendingSelects, true) as $position => $database) {
                if (array_key_exists($position, $reply) && ! $reply[$position] instanceof QueryException) {
                    $this->applySelectedDatabase($database);

                    break;
                }
            }
        }

        // A WATCH abort answers EXEC with nil, and phpredis has no null in
        // EXEC replies: both become false.
        return $command === 'EXEC'
            ? (static::toPhpRedisReply($reply, true) ?? false)
            : $reply;
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
        $this->pendingSelects = [];
        $this->queuedCommands = 0;

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

            // Rejected while queueing: false like phpredis, EXEC aborts.
            if ($e instanceof QueryException && $this->ownsTransaction()) {
                return false;
            }

            throw $e;
        }

        $time = round((microtime(true) - $start) * 1000, 2);

        $this->events?->dispatch(new CommandExecuted(
            'eval', $this->parseParametersForEvent([$script, $numberOfKeys, ...$arguments]), $time, $this
        ));

        // phpredis returns false for a nil script result (Lua nil or false).
        return $this->queuedReply($result ?? false);
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

        // Inside MULTI an EVALSHA is only queued: a NOSCRIPT would surface at
        // EXEC, too late for the fallback. Send the script itself instead.
        if (! $this->ownsTransaction()) {
            try {
                $this->waitForTransactionGate();

                return static::toPhpRedisReply(
                    $this->client->execute('evalsha', sha1($script), $count, ...$keys, ...$this->flattenParameters($args)),
                    true
                );
            } catch (QueryException $e) {
                if (! str_starts_with($e->getMessage(), 'NOSCRIPT')) {
                    throw $e;
                }
            }
        }

        $this->waitForTransactionGate();

        $result = $this->client->execute('eval', $script, $count, ...$keys, ...$this->flattenParameters($args));

        if ($this->ownsTransaction()) {
            $this->queuedCommands++;
        }

        return static::toPhpRedisReply($result, true);
    }

    /**
     * Map error replies nested inside an array reply to false, the value
     * phpredis puts in the slot of a failed command (EXEC, pipelines, EVAL
     * tables, raw commands). With $nilToFalse nested nil replies become false
     * too, as phpredis has no null inside EXEC, pipeline and EVAL replies.
     * Top-level replies are returned as they are, and top-level error
     * replies still throw.
     */
    public static function toPhpRedisReply(mixed $reply, bool $nilToFalse = false): mixed
    {
        if (! is_array($reply)) {
            return $reply;
        }

        foreach ($reply as $key => $value) {
            if ($value instanceof QueryException || ($nilToFalse && $value === null)) {
                $reply[$key] = false;
            } elseif (is_array($value)) {
                $reply[$key] = static::toPhpRedisReply($value, $nilToFalse);
            }
        }

        return $reply;
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

        $subscriptions = [];

        foreach ((array) $channels as $channel) {
            // phpredis OPT_PREFIX prefixes channel names, and subscribe
            // callbacks receive the prefixed name as sent by the server.
            $channel = $this->keyPrefixer->prefixChannel((string) $channel);

            $subscriptions[] = [$channel, $this->subscriber->subscribe($channel)];
        }

        $this->consumeSubscriptions($subscriptions, static function (mixed $message, string $channel) use ($callback): void {
            $callback($message, $channel);
        });
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe($channels, Closure $callback)
    {
        if (! $this->subscriber) {
            throw new \RuntimeException('No RedisSubscriber available for pub/sub.');
        }

        $subscriptions = [];

        foreach ((array) $channels as $pattern) {
            $pattern = $this->keyPrefixer->prefixChannel((string) $pattern);

            $subscriptions[] = [$pattern, $this->subscriber->subscribeToPattern($pattern)];
        }

        // Pattern subscriptions yield [payload, channel]; phpredis hands the
        // callback the matched channel, not the pattern.
        $this->consumeSubscriptions($subscriptions, static function (mixed $message) use ($callback): void {
            [$payload, $channel] = $message;

            $callback($payload, $channel);
        });
    }

    /**
     * Consume every subscription until all complete, like one phpredis
     * SUBSCRIBE over several channels: the callback runs in the calling
     * fiber, one message at a time, in the order the subscriber received
     * them. The first error (including one thrown by the callback) ends
     * every subscription and is rethrown.
     *
     * @param  array<int, array{0: string, 1: RedisSubscription}>  $subscriptions
     * @param  Closure(mixed, string): void  $consume
     */
    protected function consumeSubscriptions(array $subscriptions, Closure $consume): void
    {
        $futures = [];
        $merged = null;

        try {
            if (count($subscriptions) === 1) {
                [$name, $subscription] = $subscriptions[0];

                foreach ($subscription as $message) {
                    $consume($message, $name);
                }

                return;
            }

            // Each feeder hands its message to one unbuffered queue straight
            // after taking it. RedisSubscriber reads one reply at a time and
            // waits for its consumer, and fibers resume in FIFO order, so the
            // merged queue keeps the connection's arrival order.
            $queue = new Queue;
            $merged = $queue->iterate();
            $active = count($subscriptions);

            foreach ($subscriptions as [$name, $subscription]) {
                $futures[] = async(static function () use ($name, $subscription, $queue, &$active): void {
                    try {
                        foreach ($subscription as $message) {
                            $queue->push([$name, $message]);
                        }
                    } catch (Throwable $e) {
                        if (! $queue->isComplete() && ! $queue->isDisposed()) {
                            $queue->error($e);
                        }

                        return;
                    }

                    if (--$active === 0 && ! $queue->isComplete() && ! $queue->isDisposed()) {
                        $queue->complete();
                    }
                });
            }

            foreach ($merged as [$name, $message]) {
                $consume($message, $name);
            }
        } finally {
            foreach ($subscriptions as [, $subscription]) {
                $subscription->unsubscribe();
            }

            // Releases feeders blocked in push() so their fibers end.
            $merged?->dispose();

            foreach ($futures as $future) {
                $future->ignore();
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

        $result = $this->client->execute($command, ...$parameters);
        $select = strcasecmp((string) $command, 'SELECT') === 0;

        // Keeps the EXEC reply positions of queued SELECTs aligned; a raw
        // SELECT is tracked like select(). Outside this fiber's MULTI only a
        // real OK counts (a raw MULTI answers QUEUED).
        if ($this->ownsTransaction()) {
            $position = $this->queuedCommands++;

            if ($select) {
                $this->pendingSelects[$position] = (int) ($parameters[0] ?? 0);
            }
        } elseif ($select && $result === 'OK') {
            $this->applySelectedDatabase((int) ($parameters[0] ?? 0));
        }

        return $this->queuedReply(static::toPhpRedisReply($result));
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

        // A blocking call still running would never see QUIT answered (BLPOP
        // 0 blocks forever): drop its socket, so it fails as on a lost
        // connection. Anything else returning later is closed by generation.
        $this->blockingGeneration++;
        $this->disconnects++;

        $checkedOut = $this->checkedOutBlockingClients;
        $this->checkedOutBlockingClients = [];

        foreach ($checkedOut as $client) {
            $client->closeConnection();
        }

        $this->closeIdleBlockingClients();
    }

    /**
     * Pass other method calls down to the underlying client.
     */
    public function __call($method, $parameters)
    {
        return $this->command(strtolower($method), $parameters);
    }
}
