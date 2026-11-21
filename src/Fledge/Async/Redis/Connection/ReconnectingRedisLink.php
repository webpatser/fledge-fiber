<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\CancelledException;
use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Redis\Internal\PendingRedisCommand;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\TimeoutCancellation;
use Fledge\Loop;

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
     * The MULTI entry of the transaction open in send order (MULTI queued,
     * its EXEC or DISCARD not yet), tracked at enqueue time so pipelined
     * commands are covered before MULTI's own reply arrives. Reset on EXEC,
     * DISCARD or RESET (the server leaves MULTI state on each, whatever the
     * reply), when MULTI itself gets no OK reply (error reply, read timeout,
     * lost connection) and on a reconnect after the MULTI reached the lost
     * connection.
     */
    private ?PendingRedisCommand $openMulti = null;

    /**
     * Set when a reconnect dropped an open transaction while none of its
     * commands was pending; the next command fails in its place.
     */
    private bool $transactionLost = false;

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

        $pending = null;

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

            // A MULTI that was rejected, timed out or failed without a reply
            // leaves no transaction open on the server (a timeout or a lost
            // connection takes the socket with it), so later commands must
            // not be tied to it. Commands already pipelined after it keep
            // their ->multi and fail on the reconnect.
            if ($pending !== null
                && $this->openMulti === $pending
                && !(isset($response) && $response instanceof RedisValue && $response->unwrap() === 'OK')
            ) {
                $this->openMulti = null;
            }
        }

        // Only a SELECT the server applied counts: a QUEUED reply inside MULTI
        // may be discarded and an error reply (SELECT 9999) changed nothing.
        if (\strcasecmp($command, 'select') === 0 && $response instanceof RedisValue && $response->unwrap() === 'OK') {
            $this->database = (int) ($parameters[0] ?? 0);
        }

        return $response;
    }

    /**
     * Record the database a SELECT queued in MULTI switched to once EXEC
     * ran it, so a reconnect selects it again. No round trip.
     */
    public function rememberDatabase(int $database): void
    {
        $this->database = $database;
    }

    /**
     * Drop the current socket, even with a command in flight that the server
     * would never answer (BLPOP 0): such commands fail exactly as on a lost
     * connection, and the next command reconnects.
     */
    public function closeConnection(): void
    {
        $this->connection?->close();
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
            self::fail($pending, $exception);
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

        // A reconnect dropped a transaction nobody was waiting on (the socket
        // went away after MULTI was answered): this command was meant for it,
        // so fail it instead of running it outside the transaction. The
        // caller learns the transaction is gone and the link starts clean.
        if ($this->transactionLost) {
            $this->transactionLost = false;
            $pending->deferred->error(self::transactionLostException($pending));

            return $pending;
        }

        $this->trackTransaction($pending);
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

    /**
     * Tie a command to the transaction open in send order: MULTI opens one,
     * EXEC, DISCARD and RESET close it, everything in between belongs to it.
     * A nested MULTI (a Redis error) keeps the open transaction.
     */
    private function trackTransaction(PendingRedisCommand $pending): void
    {
        $name = \strtolower($pending->command);

        if ($this->openMulti !== null) {
            $pending->multi = $this->openMulti;

            if ($name === 'exec' || $name === 'discard' || $name === 'reset') {
                $this->openMulti = null;
            }

            return;
        }

        if ($name === 'multi') {
            $pending->multi = $pending;
            $this->openMulti = $pending;
        }
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
        $openMulti = &$this->openMulti;
        $transactionLost = &$this->transactionLost;

        Loop::queue(static function () use (
            &$connection,
            &$running,
            &$database,
            &$openMulti,
            &$transactionLost,
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

                    /* The link was destroyed while connecting: nothing would
                     * ever close this socket, so drop it now. */
                    if (!$running) {
                        $connection->close();
                        $connection = null;

                        return;
                    }

                    $connection->unreference();

                    try {
                        self::settleInFlightAndResend($queue, $connection, $openMulti, $transactionLost);

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

                        /* Closed (QUIT, closeConnection(), server side) with
                         * nothing waiting: stop and let the next execute()
                         * reconnect, as phpredis does, instead of holding an
                         * eager new socket nobody may ever use or close. */
                        if (self::hasNoPendingCommands($queue)) {
                            $running = false;

                            return;
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
     * A transaction whose MULTI reached a lost connection is gone server
     * side: its commands, sent or not, retryable or not, fail with a
     * RedisInFlightCommandException instead of running outside the
     * transaction on the new connection, and the link leaves MULTI state.
     * When none of an open lost transaction's commands is pending, the next
     * command enqueued fails in their place ($transactionLost).
     *
     * @param \SplQueue<PendingRedisCommand> $queue
     *
     * @throws RedisException when sending on the new connection fails.
     */
    private static function settleInFlightAndResend(
        \SplQueue $queue,
        RedisConnection $connection,
        ?PendingRedisCommand &$openMulti,
        bool &$transactionLost,
    ): void {
        // Nothing has been sent on the new connection yet, so a sent MULTI
        // was sent on a lost one.
        // A MULTI that failed unsent (read timeout) opened nothing either.
        $lost = self::transactionGone($openMulti) ? $openMulti : null;

        if ($lost !== null) {
            $openMulti = null;
        }

        $retained = [];

        while (!$queue->isEmpty()) {
            /** @var PendingRedisCommand $pending */
            $pending = $queue->shift();

            if ($pending->deferred->isComplete()) {
                continue;
            }

            if ($pending->sent && !RetryableCommands::isRetryable($pending->command, $pending->parameters)) {
                self::fail($pending, new RedisInFlightCommandException(\sprintf(
                    'Redis connection lost while %s was in flight: the command may have executed on the server; not resent',
                    \strtoupper($pending->command),
                )));

                continue;
            }

            if (self::transactionGone($pending->multi)) {
                self::fail($pending, self::transactionLostException($pending));

                continue;
            }

            $retained[] = $pending;
        }

        if ($lost !== null && !$lost->transactionFailed) {
            $transactionLost = true;
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
     * Whether the transaction a command belongs to can no longer run: its
     * MULTI was sent on a connection that is now lost, or failed unsent.
     * Only called while no command has been sent on the new connection.
     */
    private static function transactionGone(?PendingRedisCommand $multi): bool
    {
        return $multi !== null && ($multi->sent || $multi->deferred->isComplete());
    }

    /**
     * Fail a command and, inside a transaction, record that its caller has
     * been told the transaction failed.
     */
    private static function fail(PendingRedisCommand $pending, RedisException $exception): void
    {
        $pending->deferred->error($exception);

        if ($pending->multi !== null) {
            $pending->multi->transactionFailed = true;
        }
    }

    private static function transactionLostException(PendingRedisCommand $pending): RedisInFlightCommandException
    {
        return new RedisInFlightCommandException(\sprintf(
            'Redis connection lost inside MULTI before %s was %s: the server discarded the transaction; not resent',
            \strtoupper($pending->command),
            $pending->sent ? 'answered' : 'sent',
        ));
    }

    /**
     * Whether every queued entry is settled (timed out or answered); if so
     * the queue is emptied so a later run() starts clean.
     *
     * @param \SplQueue<PendingRedisCommand> $queue
     */
    private static function hasNoPendingCommands(\SplQueue $queue): bool
    {
        foreach ($queue as $pending) {
            if (!$pending->deferred->isComplete()) {
                return false;
            }
        }

        while (!$queue->isEmpty()) {
            $queue->shift();
        }

        return true;
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
                self::fail($pending, $exception);
            }
        }
    }
}
