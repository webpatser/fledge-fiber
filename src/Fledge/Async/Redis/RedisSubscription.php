<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\ConcurrentIterator;
use Fledge\Async\DisposedException;
use Revolt\EventLoop;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class RedisSubscription implements \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var null|\Closure():void */
    private ?\Closure $unsubscribe;

    /**
     * @param \Closure():void $unsubscribe
     */
    public function __construct(
        private readonly ConcurrentIterator $iterator,
        \Closure $unsubscribe,
    ) {
        $this->unsubscribe = $unsubscribe;
    }

    public function __destruct()
    {
        $this->unsubscribe();
    }

    /**
     * Using a Generator to maintain a reference to $this.
     */
    public function getIterator(): \Traversable
    {
        yield from $this->iterator;
    }

    public function unsubscribe(): void
    {
        if ($this->unsubscribe) {
            EventLoop::queue($this->unsubscribe);
            $this->unsubscribe = null;
        }

        try {
            $this->iterator->dispose();
        } catch (DisposedException) {
            // Already disposed, ignore.
        }
    }
}
