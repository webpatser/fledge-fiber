<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Protocol\QueryException;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

class FledgeRedisPipeline
{
    /**
     * The queued commands.
     */
    protected array $commands = [];

    /**
     * Create a new pipeline instance.
     */
    public function __construct(
        protected FledgeRedisConnection $connection,
    ) {
    }

    /**
     * Queue a command for execution.
     */
    public function __call(string $method, array $parameters): static
    {
        $this->commands[] = [$method, $parameters];

        return $this;
    }

    /**
     * Execute all queued commands concurrently and return their results.
     */
    public function exec(): array
    {
        // Inside this fiber's MULTI the commands must stay on this fiber:
        // child fibers would wait on the transaction lock this fiber holds.
        // A blocking command runs on a connection of its own, so a batch
        // holding one runs in order to keep phpredis's one-socket ordering.
        if ($this->connection->ownsTransaction() || $this->holdsBlockingCommand()) {
            $results = [];

            foreach ($this->commands as [$method, $parameters]) {
                $results[] = $this->run($method, $parameters);
            }

            $this->commands = [];

            return $results;
        }

        $futures = [];

        foreach ($this->commands as [$method, $parameters]) {
            $futures[] = async(fn () => $this->run($method, $parameters));
        }

        $this->commands = [];

        // await() keys the replies by command index but orders them by
        // completion, so restore command order before returning a list.
        $results = await($futures);
        ksort($results);

        return array_values($results);
    }

    /**
     * Whether a queued command would run on a blocking connection.
     */
    protected function holdsBlockingCommand(): bool
    {
        foreach ($this->commands as [$method, $parameters]) {
            if ($this->connection->blocksConnection($method, $parameters)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run one queued command. Like phpredis, a Redis error reply or a nil
     * reply (missing key, nil bulk or nil array) puts false in the command's
     * slot, also nested inside an array reply (MGET elements). A BLPOP or
     * BRPOP timeout gives an empty array, as in phpredis. Connection-level
     * failures still throw.
     */
    protected function run(string $method, array $parameters): mixed
    {
        try {
            $reply = $this->connection->command($method, $parameters);
        } catch (QueryException) {
            return false;
        }

        if ($reply === null) {
            return in_array(strtolower($method), ['blpop', 'brpop'], true) ? [] : false;
        }

        return FledgeRedisConnection::toPhpRedisReply($reply, true);
    }
}
