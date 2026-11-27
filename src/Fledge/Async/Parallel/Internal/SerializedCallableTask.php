<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Internal;

use Fledge\Async\Cancellation;
use Fledge\Async\Parallel\Worker\Task;
use Fledge\Async\Sync\Channel;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Runs a callable serialized by {@see \Fledge\Async\Parallel\parallel()} in a worker.
 *
 * The serialized string is produced by the parent process: a closure arrives wrapped in a
 * {@see SerializableClosure}, any other callable (function name, static method string or array)
 * arrives as is.
 *
 * @internal
 * @implements Task<mixed, never, never>
 */
final class SerializedCallableTask implements Task
{
    /**
     * @param string $callable Serialized callable.
     * @param array $args Arguments to invoke the callable with.
     */
    public function __construct(
        private readonly string $callable,
        private readonly array $args = [],
    ) {
    }

    /**
     * @throws \Error If the serialized data does not describe a callable.
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $callable = \unserialize($this->callable);

        if ($callable instanceof \__PHP_Incomplete_Class) {
            throw new \Error(
                'Could not unserialize the callable in the worker: its class is not autoloadable there'
                . ' (closures need laravel/serializable-closure)'
            );
        }

        if ($callable instanceof SerializableClosure) {
            $callable = $callable->getClosure();
        }

        if (!\is_callable($callable)) {
            throw new \Error(\sprintf(
                'The serialized value is not callable in the worker, %s given',
                \get_debug_type($callable)
            ));
        }

        return $callable(...$this->args);
    }
}
