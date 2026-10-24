<?php

namespace Fledge\Fiber\Redis;

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
        if ($this->connection->ownsTransaction()) {
            $results = [];

            foreach ($this->commands as [$method, $parameters]) {
                $results[] = $this->connection->command($method, $parameters);
            }

            $this->commands = [];

            return $results;
        }

        $futures = [];

        foreach ($this->commands as [$method, $parameters]) {
            $futures[] = async(fn () => $this->connection->command($method, $parameters));
        }

        $this->commands = [];

        return await($futures);
    }
}
