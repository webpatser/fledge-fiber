<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\CancelledException;
use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\Internal\PendingRedisCommand;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\TimeoutCancellation;
use Revolt\EventLoop;

final class ReconnectingRedisLink implements RedisLink
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \SplQueue<PendingRedisCommand> */
    private readonly \SplQueue $queue;

    private readonly BackoffStrategy $backoff;

    private ?int $database = null;

    private bool $running = false;

    private ?RedisConnection $connection = null;

    /**
     * @param ?float $readTimeout Maximum seconds to wait for a single command
     *     response (phpredis read_timeout); null waits forever.
     * @param ?int $maxReconnectAttempts Bound on consecutive failed reconnect
     *     attempts before pending commands are failed; null retries forever.
     */
    public function __construct(
        private readonly RedisConnector $connector,
        private readonly ?float $readTimeout = null,
        ?BackoffStrategy $backoff = null,
        private readonly ?int $maxReconnectAttempts = null,
    ) {
        $this->backoff = $backoff ?? BackoffStrategy::default();
        $this->queue = new \SplQueue();
    }

    public function __destruct()
    {
        $this->running = false;
        $this->connection?->close();
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        if (!$this->running) {
            $this->run();
        }

        $parameters = \array_values(\array_map(\strval(...), $parameters));

        try {
            $pending = $this->enqueue($command, $parameters);

            try {
                $response = $this->readTimeout !== null
                    ? $pending->deferred->getFuture()->await(new TimeoutCancellation($this->readTimeout))
                    : $pending->deferred->getFuture()->await();
            } catch (CancelledException) {
                throw $this->timeout($pending);
            }
        } finally {
            if (\strcasecmp($command, 'quit') === 0) {
                $this->connection?->close();
            }
        }

        if (\strcasecmp($command, 'select') === 0) {
            $this->database = (int) ($parameters[0] ?? 0);
        }

        return $response;
    }

    /**
     * The read timeout fired: the response for this command (and response
     * alignment as a whole) is now unknown, so the connection is closed. The
     * entry stays queued; the reconnect logic skips settled entries.
     */
    private function timeout(PendingRedisCommand $pending): RedisTimeoutException
    {
        $exception = new RedisTimeoutException(\sprintf(
            'Redis read error on connection: no response to %s within %.3f seconds',
            $pending->command,
            $this->readTimeout,
        ));

        if (!$pending->deferred->isComplete()) {
            $pending->deferred->error($exception);
        }

        $this->connection?->close();

        return $exception;
    }

    /**
     * @param list<string> $parameters
     */
    private function enqueue(string $command, array $parameters): PendingRedisCommand
    {
        $pending = new PendingRedisCommand(new DeferredFuture(), $command, $parameters);
        $this->queue->push($pending);

        $this->connection?->reference();

        try {
            if ($this->connection !== null) {
                $this->connection->send($command, ...$parameters);
                $pending->sent = true;
            }
        } catch (RedisException) {
            $this->connection = null;
        }

        return $pending;
    }

    private function run(): void
    {
        $connector = $this->connector;
        $queue = $this->queue;
        $backoff = $this->backoff;
        $maxReconnectAttempts = $this->maxReconnectAttempts;
        $running = &$this->running;
        $connection = &$this->connection;
        $database = &$this->database;

        EventLoop::queue(static function () use (
            &$connection,
            &$running,
            &$database,
            $queue,
            $connector,
            $backoff,
            $maxReconnectAttempts,
        ): void {
            try {
                $failures = 0;
                $lastDelay = null;

                while ($running) {
                    if ($failures > 0) {
                        /* Back off before reconnecting: without this, a failure
                         * that recurs immediately (e.g. a deterministic parse
                         * error) turns the loop into a connect storm that can
                         * exhaust the local ephemeral port range within
                         * seconds. */
                        $lastDelay = $backoff->delay($failures, $lastDelay);
                        \Fledge\Async\delay($lastDelay);
                    }

                    try {
                        if ($database !== null) {
                            $connection = (new DatabaseSelector($database, $connector))->connect();
                        } else {
                            $connection = $connector->connect();
                        }
                    } catch (RedisException $exception) {
                        $failures++;

                        /* Without a configured max_retries, a failed connect is
                         * terminal (historic behavior); with one, connecting is
                         * retried until the bound is exhausted. */
                        if ($maxReconnectAttempts === null || $failures > $maxReconnectAttempts) {
                            self::drainQueue($queue, $exception instanceof RedisConnectionException
                                ? $exception
                                : new RedisConnectionException($exception->getMessage(), 0, $exception));
                            $running = false;

                            return;
                        }

                        continue;
                    }

                    $connection->unreference();

                    try {
                        self::settleInFlightAndResend($queue, $connection);

                        while ($response = $connection->receive()) {
                            $failures = 0;
                            $lastDelay = null;

                            /** @var PendingRedisCommand $pending */
                            $pending = $queue->shift();
                            if ($queue->isEmpty()) {
                                $connection->unreference();
                            }

                            if (!$pending->deferred->isComplete()) {
                                $pending->deferred->complete($response);
                            }
                        }
                    } catch (RedisWireException $exception) {
                        /* A corrupt wire stream is not transient for the
                         * commands in flight: resending them would corrupt the
                         * same way and loop forever. Fail them and reconnect
                         * with a clean queue. */
                        $failures++;

                        self::drainQueue($queue, $exception);

                        if ($maxReconnectAttempts !== null && $failures > $maxReconnectAttempts) {
                            $running = false;

                            return;
                        }
                    } catch (RedisException $exception) {
                        // Attempt to reconnect after failure.
                        $failures++;

                        if ($maxReconnectAttempts !== null && $failures > $maxReconnectAttempts) {
                            self::drainQueue($queue, $exception);
                            $running = false;

                            return;
                        }
                    } finally {
                        $connection = null;
                    }
                }
            } catch (\Throwable $exception) {
                self::drainQueue($queue, new RedisConnectionException($exception->getMessage(), 0, $exception));

                $running = false;
            }
        });

        $this->running = true;
    }

    /**
     * Rebuild the queue after a (re)connect so response alignment holds:
     * settled entries (timed out or already answered) are dropped, commands
     * that were sent on a previous connection are resent only when safely
     * retryable and failed with a RedisInFlightCommandException otherwise,
     * and never-sent commands are simply sent.
     *
     * @param \SplQueue<PendingRedisCommand> $queue
     *
     * @throws RedisException when sending on the new connection fails.
     */
    private static function settleInFlightAndResend(\SplQueue $queue, RedisConnection $connection): void
    {
        $retained = [];

        while (!$queue->isEmpty()) {
            /** @var PendingRedisCommand $pending */
            $pending = $queue->shift();

            if ($pending->deferred->isComplete()) {
                continue;
            }

            if ($pending->sent && !RetryableCommands::isRetryable($pending->command, $pending->parameters)) {
                $pending->deferred->error(new RedisInFlightCommandException(\sprintf(
                    'Redis connection lost while %s was in flight: the command may have executed on the server; not resent',
                    \strtoupper($pending->command),
                )));

                continue;
            }

            $retained[] = $pending;
        }

        foreach ($retained as $pending) {
            $queue->push($pending);
        }

        foreach ($retained as $pending) {
            $connection->reference();
            $connection->send($pending->command, ...$pending->parameters);
            $pending->sent = true;
        }
    }

    /**
     * @param \SplQueue<PendingRedisCommand> $queue
     */
    private static function drainQueue(\SplQueue $queue, RedisException $exception): void
    {
        while (!$queue->isEmpty()) {
            /** @var PendingRedisCommand $pending */
            $pending = $queue->shift();

            if (!$pending->deferred->isComplete()) {
                $pending->deferred->error($exception);
            }
        }
    }
}
