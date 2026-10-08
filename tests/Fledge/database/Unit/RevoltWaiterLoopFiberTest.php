<?php

use Fledge\Fiber\Database\Native\RevoltWaiter;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * With HOOK_SLEEP on, fiberio also hooks the usleep() Revolt's own loop fiber
 * makes while it idles until the next timer. The waiter must block there
 * instead of suspending the loop fiber on itself, which tripped Revolt's
 * assertion and hung the process with assertions off.
 */
it('lets the event loop fiber idle on timers with the sleep hook on', function () {
    if (! extension_loaded('fiberio') || ! defined('FiberIo\\HOOK_SLEEP')) {
        $this->markTestSkipped('needs php-fiberio >= 0.2 (HOOK_SLEEP)');
    }

    $enabledHere = ! \FiberIo\enabled();

    if ($enabledHere) {
        \FiberIo\enable(new RevoltWaiter, \FiberIo\HOOK_ALL);
    }

    try {
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

        $elapsed = (hrtime(true) - $start) / 1e9;

        expect($results)->toBe(['usleep', 'delay'])
            ->and($elapsed)->toBeGreaterThanOrEqual(0.09)
            ->and($elapsed)->toBeLessThan(0.5);
    } finally {
        if ($enabledHere) {
            \FiberIo\disable();
        }
    }
});
