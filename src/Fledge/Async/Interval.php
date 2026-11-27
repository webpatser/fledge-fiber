<?php declare(strict_types=1);

namespace Fledge\Async;

use Fledge\Loop;
use Time\Duration;

/**
 * This object invokes the given callback within a new coroutine every $interval seconds until either the
 * {@see self::disable()} method is called or the object is destroyed.
 */
final class Interval
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly string $callbackId;

    /**
     * @param Duration|float $interval Invoke the function every $interval, as a `Duration` or in seconds. Float INF
     *     never invokes it.
     * @param \Closure():void $closure Use {@see weakClosure()} to avoid a circular reference if storing this object
     *      as a property of another object.
     * @param bool $reference If false, unreference the underlying event-loop callback.
     *
     * @throws \Error When the interval is negative (a negated `Duration` included) or NAN.
     */
    public function __construct(Duration|float $interval, \Closure $closure, bool $reference = true)
    {
        $this->callbackId = Loop::repeat($interval, $closure);

        if (!$reference) {
            Loop::unreference($this->callbackId);
        }
    }

    public function __destruct()
    {
        Loop::cancel($this->callbackId);
    }

    /**
     * @return bool True if the internal event-loop callback is referenced.
     */
    public function isReferenced(): bool
    {
        return Loop::isReferenced($this->callbackId);
    }

    /**
     * References the internal event-loop callback, keeping the loop running while the repeat loop is enabled.
     *
     * @return $this
     */
    public function reference(): self
    {
        Loop::reference($this->callbackId);

        return $this;
    }

    /**
     * Unreferences the internal event-loop callback, allowing the loop to stop while the repeat loop is enabled.
     *
     * @return $this
     */
    public function unreference(): self
    {
        Loop::unreference($this->callbackId);

        return $this;
    }

    /**
     * @return bool True if the repeating timer is enabled.
     */
    public function isEnabled(): bool
    {
        return Loop::isEnabled($this->callbackId);
    }

    /**
     * Restart the repeating timer if previously stopped with {@see self::disable()}.
     *
     * @return $this
     */
    public function enable(): self
    {
        Loop::enable($this->callbackId);

        return $this;
    }

    /**
     * Stop the repeating timer. Restart it with {@see self::enable()}.
     *
     * @return $this
     */
    public function disable(): self
    {
        Loop::disable($this->callbackId);

        return $this;
    }
}
