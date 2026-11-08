<?php

use Fledge\Fiber\Database\Native\RevoltWaiter;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Enables fiberio with the real RevoltWaiter behind a decorator that counts
 * timer waits (null stream, events 0), runs $body, and returns the count and
 * the user CPU time it took.
 *
 * @return array{0: mixed, 1: int, 2: float}
 */
function runWithCountedRevoltWaiter(Closure $body): array
{
    if (\FiberIo\enabled()) {
        \FiberIo\disable();
    }

    $real = new RevoltWaiter;
    $timerCalls = 0;

    \FiberIo\enable(function ($stream, int $events, ?float $timeout) use ($real, &$timerCalls): bool {
        if ($stream === null || $events === 0) {
            $timerCalls++;
        }

        return $real($stream, $events, $timeout);
    }, \FiberIo\HOOK_ALL);

    try {
        $before = getrusage();
        $result = $body();
        $after = getrusage();
    } finally {
        \FiberIo\disable();
    }

    $cpu = ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'])
        + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1e6;

    return [$result, $timerCalls, $cpu];
}

beforeEach(function () {
    if (! extension_loaded('fiberio') || ! defined('FiberIo\\HOOK_SLEEP')) {
        $this->markTestSkipped('needs php-fiberio >= 0.2 (HOOK_SLEEP)');
    }
});

/**
 * With HOOK_SLEEP on, fiberio also hooks the usleep() Revolt's own loop fiber
 * makes while it idles until the next timer. The waiter must not suspend the
 * loop fiber on itself (that tripped Revolt's assertion and hung the process
 * with assertions off), and must not report the wait as woken either (that
 * ended every idle usleep() at once and busy-spun the loop at 100% CPU).
 */
it('lets the event loop fiber idle on timers with the sleep hook on', function () {
    [$results, $timerCalls, $cpu] = runWithCountedRevoltWaiter(function () {
        $start = hrtime(true);

        $results = await([
            async(function () {
                usleep(100_000);

                return 'usleep';
            }),
            async(function () {
                delay(0.1);

                return 'delay';
            }),
        ]);

        return [$results, (hrtime(true) - $start) / 1e9];
    });

    [$values, $elapsed] = $results;

    expect($values)->toBe(['usleep', 'delay'])
        ->and($elapsed)->toBeGreaterThanOrEqual(0.09)
        ->and($elapsed)->toBeLessThan(0.5)
        ->and($timerCalls)->toBeLessThanOrEqual(10)
        ->and($cpu)->toBeLessThan(0.05);
});

it('does not busy-spin the loop fiber while it idles until a timer', function () {
    [, $timerCalls, $cpu] = runWithCountedRevoltWaiter(function () {
        EventLoop::delay(0.1, static fn () => null);
        EventLoop::run();
    });

    expect($timerCalls)->toBeLessThanOrEqual(4)
        ->and($cpu)->toBeLessThan(0.05);
});
