<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Future;
use Fledge\Async\Queue;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\Protocol\RedisError;
use Revolt\EventLoop;
use function Fledge\Async\async;

final class RedisSubscriber
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?RedisConnection $connection = null;

    private bool $running = false;

    /** @var array<string, array<int, Queue>> */
    private array $queues = [];

    /** @var array<string, array<int, Queue>> */
    private array $patternQueues = [];

    /**
     * (P)(UN)SUBSCRIBE commands sent on the current connection and not yet
     * answered, in send order, so an error reply maps to its channel.
     *
     * @var list<array{string, string}>
     */
    private array $pending = [];

    /** Set while the connection is being closed because no subscription is left. */
    private bool $closing = false;

    private readonly BackoffStrategy $backoff;

    public function __construct(
        private readonly RedisConnector $connector,
        ?BackoffStrategy $backoff = null,
    ) {
        $this->backoff = $backoff ?? BackoffStrategy::default();
    }

    public function __destruct()
    {
        $this->running = false;
        $this->connection?->close();
    }

    public function subscribe(string $channel): RedisSubscription
    {
        if (!$this->running) {
            $this->run();
        }

        $subscribe = !isset($this->queues[$channel]);

        $queue = new Queue();
        $this->queues[$channel][\spl_object_id($queue)] = $queue;

        if ($subscribe) {
            try {
                // While closing, the loop resubscribes on the next connection.
                if ($this->connection !== null && !$this->closing) {
                    self::sendTracked($this->pending, $this->connection, 'subscribe', $channel);
                }
            } catch (\Throwable $e) {
                $this->unloadEmitter($queue, $channel);

                throw $e;
            }
        }

        return new RedisSubscription($queue->iterate(), fn () => $this->unloadEmitter($queue, $channel));
    }

    public function subscribeToPattern(string $pattern): RedisSubscription
    {
        if (!$this->running) {
            $this->run();
        }

        $subscribe = !isset($this->patternQueues[$pattern]);

        $queue = new Queue();
        $this->patternQueues[$pattern][\spl_object_id($queue)] = $queue;

        if ($subscribe) {
            try {
                // While closing, the loop resubscribes on the next connection.
                if ($this->connection !== null && !$this->closing) {
                    self::sendTracked($this->pending, $this->connection, 'psubscribe', $pattern);
                }
            } catch (\Throwable $e) {
                $this->unloadPatternEmitter($queue, $pattern);

                throw $e;
            }
        }

        return new RedisSubscription($queue->iterate(), fn () => $this->unloadPatternEmitter($queue, $pattern));
    }

    private function run(): void
    {
        $connector = $this->connector;
        $running = &$this->running;
        $connection = &$this->connection;
        $queues = &$this->queues;
        $patternQueues = &$this->patternQueues;
        $pending = &$this->pending;
        $closing = &$this->closing;
        $backoff = $this->backoff;

        EventLoop::queue(static function () use (
            &$running,
            &$connection,
            &$queues,
            &$patternQueues,
            &$pending,
            &$closing,
            $connector,
            $backoff,
        ): void {
            try {
                $failures = 0;
                $lastDelay = null;

                while ($running) {
                    if ($failures > 0) {
                        /* Back off before reconnecting: a server that drops the
                         * connection right after SUBSCRIBE otherwise turns this
                         * loop into a connect storm. */
                        $lastDelay = $backoff->delay($failures, $lastDelay);
                        \Fledge\Async\delay($lastDelay);

                        if (!$running) {
                            break;
                        }
                    }

                    $connection = $connector->connect();
                    $pending = [];

                    try {
                        foreach (\array_keys($queues) as $queue) {
                            self::sendTracked($pending, $connection, 'subscribe', (string) $queue);
                        }

                        foreach (\array_keys($patternQueues) as $pattern) {
                            self::sendTracked($pending, $connection, 'psubscribe', (string) $pattern);
                        }

                        while ($reply = $connection->receive()) {
                            if ($reply instanceof RedisError) {
                                /* An error reply (e.g. -NOPERM) answers the oldest
                                 * unanswered (p)subscribe: fail that subscription
                                 * like phpredis throws to the subscribe() caller,
                                 * instead of reconnecting into the same rejection. */
                                $error = new QueryException($reply->getMessage());
                                $command = \array_shift($pending);

                                if ($command === null) {
                                    throw $error;
                                }

                                [$kind, $name] = $command;

                                if ($kind === 'subscribe') {
                                    self::failQueues($queues, $name, $error);
                                } elseif ($kind === 'psubscribe') {
                                    self::failQueues($patternQueues, $name, $error);
                                }

                                continue;
                            }

                            $response = $reply->unwrap();

                            /** @psalm-suppress RedundantCondition */
                            \assert(
                                \is_array($response) && \array_is_list($response),
                                'Expected a list from RespSocket on subscription',
                            );

                            switch ($response[0]) {
                                case 'message':
                                    $failures = 0;
                                    $lastDelay = null;
                                    self::mapToQueues($queues[$response[1]] ?? [], $response[2]);
                                    break;

                                case 'pmessage':
                                    $failures = 0;
                                    $lastDelay = null;
                                    self::mapToQueues($patternQueues[$response[1]] ?? [], [$response[3], $response[2]]);
                                    break;

                                case 'subscribe':
                                case 'psubscribe':
                                    /* The server accepted a subscription: the
                                     * connection works, so a later drop (e.g. a
                                     * proxy idle timeout) starts the backoff from
                                     * the first attempt again. */
                                    $failures = 0;
                                    $lastDelay = null;
                                    \array_shift($pending);
                                    break;

                                case 'unsubscribe':
                                case 'punsubscribe':
                                    \array_shift($pending);
                                    break;
                            }
                        }

                        // EOF: the server closed the connection.
                    } catch (RedisException) {
                        /* Read or write failure, or an error reply that matches
                         * no sent command (thrown above): reconnect. */
                    } finally {
                        $connection = null;
                        $pending = [];
                    }

                    if ($closing) {
                        /* The connection was closed on purpose after the last
                         * unsubscribe: not a failure. Stop when still idle, so
                         * the next subscribe() starts a fresh loop and connects
                         * at once; reconnect at once when a subscribe arrived
                         * meanwhile. */
                        $closing = false;

                        if (!$queues && !$patternQueues) {
                            $running = false;

                            break;
                        }

                        continue;
                    }

                    $failures++;
                }
            } catch (\Throwable $exception) {
                $closing = false;
                $exception = new RedisConnectionException($exception->getMessage(), 0, $exception);

                $queueGroups = \array_merge($queues, $patternQueues);

                $queues = [];
                $patternQueues = [];

                foreach ($queueGroups as $queueGroup) {
                    foreach ($queueGroup as $queue) {
                        $queue->error($exception);
                    }
                }

                $running = false;
            }
        });

        $this->running = true;
    }

    private function isIdle(): bool
    {
        return !$this->queues && !$this->patternQueues;
    }

    /**
     * Closes the connection once no subscription is left. The run loop sees
     * $closing and treats the close as deliberate, not as a failure.
     */
    private function closeIfIdle(): void
    {
        if ($this->isIdle() && $this->connection !== null && !$this->closing) {
            $this->closing = true;
            $this->connection->close();
        }
    }

    private function unloadEmitter(Queue $queue, string $channel): void
    {
        $hash = \spl_object_id($queue);

        if (isset($this->queues[$channel][$hash])) {
            unset($this->queues[$channel][$hash]);

            $queue->complete();

            if (empty($this->queues[$channel])) {
                unset($this->queues[$channel]);

                async(function () use ($channel): void {
                    try {
                        if (empty($this->queues[$channel]) && $this->connection !== null && !$this->closing) {
                            self::sendTracked($this->pending, $this->connection, 'unsubscribe', $channel);
                        }

                        $this->closeIfIdle();
                    } catch (RedisException) {
                        // if there's an exception, the unsubscribe is implicitly successful, because the connection broke
                    }
                })->ignore();
            }
        }
    }

    private function unloadPatternEmitter(Queue $queue, string $pattern): void
    {
        $hash = \spl_object_id($queue);

        if (isset($this->patternQueues[$pattern][$hash])) {
            unset($this->patternQueues[$pattern][$hash]);

            $queue->complete();

            if (empty($this->patternQueues[$pattern])) {
                unset($this->patternQueues[$pattern]);

                async(function () use ($pattern): void {
                    try {
                        if (empty($this->patternQueues[$pattern]) && $this->connection !== null && !$this->closing) {
                            self::sendTracked($this->pending, $this->connection, 'punsubscribe', $pattern);
                        }

                        $this->closeIfIdle();
                    } catch (RedisException) {
                        // if there's an exception, the unsubscribe is implicitly successful, because the connection broke
                    }
                })->ignore();
            }
        }
    }

    /**
     * @param list<array{string, string}> $pending
     */
    private static function sendTracked(array &$pending, RedisConnection $connection, string $command, string $name): void
    {
        $pending[] = [$command, $name];
        $connection->send($command, $name);
    }

    /**
     * @param array<string, array<int, Queue>> $queues
     */
    private static function failQueues(array &$queues, string $name, \Throwable $error): void
    {
        $group = $queues[$name] ?? [];
        unset($queues[$name]);

        foreach ($group as $queue) {
            $queue->error($error);
        }
    }

    /**
     * @param array<int, Queue> $queues
     */
    private static function mapToQueues(array $queues, mixed $value): void
    {
        $backpressure = [];
        foreach ($queues as $queue) {
            $backpressure[] = $queue->pushAsync($value);
        }
        Future\awaitAll($backpressure);
    }
}
