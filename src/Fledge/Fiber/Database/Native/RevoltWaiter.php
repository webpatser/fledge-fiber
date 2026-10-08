<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use const FiberIo\READABLE;
use const FiberIo\WRITABLE;

use Revolt\EventLoop;

/**
 * Waiter for FiberIo\enable() that parks the current fiber on the Revolt
 * event loop until the stream is ready or the timeout expires.
 *
 *     \FiberIo\enable(new RevoltWaiter);
 *
 * fiberio only calls the waiter from inside a running fiber, so the
 * suspension always belongs to a fiber the loop can resume.
 *
 * Copy of php-fiberio's src/RevoltWaiter.php, shipped here so the native
 * driver does not depend on the extension's PHP sources.
 */
final class RevoltWaiter
{
    /**
     * @param  resource  $stream  Stream wrapping a dup of the socket, only for watching.
     * @param  int  $events  Bitmask of FiberIo\READABLE and FiberIo\WRITABLE.
     * @param  float|null  $timeout  Seconds, or null to wait without a limit.
     * @return bool True when the stream is ready, false on timeout.
     */
    public function __invoke($stream, int $events, ?float $timeout): bool
    {
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
}
