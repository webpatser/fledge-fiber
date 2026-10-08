<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use const FiberIo\READABLE;
use const FiberIo\WRITABLE;

use Revolt\EventLoop;
use Revolt\EventLoop\Internal\AbstractDriver;

/**
 * Waiter for FiberIo\enable() that parks the current fiber on the Revolt
 * event loop until the stream is ready or the timeout expires.
 *
 *     \FiberIo\enable(new RevoltWaiter);
 *
 * fiberio only calls the waiter from inside a running fiber. One of those
 * fibers is the event loop's own: with HOOK_SLEEP on, Revolt's idle
 * usleep() until the next timer is hooked too. That fiber cannot park on
 * itself, so there the waiter blocks instead (see waitInLoopFiber()).
 *
 * Copy of php-fiberio's src/RevoltWaiter.php, shipped here so the native
 * driver does not depend on the extension's PHP sources.
 */
final class RevoltWaiter
{
    /**
     * @param  resource|null  $stream  Stream wrapping a dup of the socket, only for watching; null for a timer wait (events 0).
     * @param  int  $events  Bitmask of FiberIo\READABLE and FiberIo\WRITABLE.
     * @param  float|null  $timeout  Seconds, or null to wait without a limit.
     * @return bool True when the stream is ready, false on timeout.
     */
    public function __invoke($stream, int $events, ?float $timeout): bool
    {
        if (self::inLoopFiber()) {
            return self::waitInLoopFiber($stream, $events, $timeout);
        }

        $suspension = EventLoop::getSuspension();
        $done = false;

        // Several watchers can fire in the same tick: only the first resumes.
        $ready = static function () use ($suspension, &$done): void {
            if (! $done) {
                $done = true;
                $suspension->resume(true);
            }
        };

        $ids = [];

        try {
            if ($events & READABLE) {
                $ids[] = EventLoop::onReadable($stream, $ready);
            }
            if ($events & WRITABLE) {
                $ids[] = EventLoop::onWritable($stream, $ready);
            }
            if ($timeout !== null) {
                $ids[] = EventLoop::delay(\max(0.0, $timeout), static function () use ($suspension, &$done): void {
                    if (! $done) {
                        $done = true;
                        $suspension->resume(false);
                    }
                });
            }

            return $suspension->suspend();
        } finally {
            foreach ($ids as $id) {
                EventLoop::cancel($id);
            }
        }
    }

    /**
     * Wait without suspending, for a call from the event loop fiber itself.
     *
     * A timer call returns true ("woken early"): fiberio retries once and
     * then sleeps the rest blocking, which is exactly the stock usleep()
     * the loop asked for. A stream call blocks in stream_select() on the
     * watch-only dup.
     *
     * @param  resource|null  $stream
     */
    private static function waitInLoopFiber($stream, int $events, ?float $timeout): bool
    {
        if ($stream === null || $events === 0) {
            return true;
        }

        $read = ($events & READABLE) ? [$stream] : null;
        $write = ($events & WRITABLE) ? [$stream] : null;
        $except = null;

        if ($timeout === null) {
            $seconds = null;
            $micro = null;
        } else {
            $timeout = \max(0.0, $timeout);
            $seconds = (int) $timeout;
            $micro = (int) (($timeout - $seconds) * 1_000_000);
        }

        return (int) @\stream_select($read, $write, $except, $seconds, $micro) > 0;
    }

    /**
     * Whether the current fiber is the Revolt event loop's own fiber, which
     * must never suspend through EventLoop::getSuspension().
     */
    private static function inLoopFiber(): bool
    {
        $current = \Fiber::getCurrent();

        if ($current === null) {
            return false;
        }

        $driver = EventLoop::getDriver();

        if (! $driver instanceof AbstractDriver) {
            return false;
        }

        static $loopFiber = null;
        $loopFiber ??= \Closure::bind(
            static fn (AbstractDriver $driver): ?\Fiber => $driver->fiber ?? null,
            null,
            AbstractDriver::class,
        );

        return $loopFiber($driver) === $current;
    }
}
