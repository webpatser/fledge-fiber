<?php

use Fledge\Async\DeferredFuture;
use Fledge\Async\Internal\UnhandledFutureError;
use Fledge\Loop;

/*
 * An errored future that is garbage-collected without ->await()/->ignore() reports itself
 * through the event-loop error handler as UnhandledFutureError. FutureState imported the
 * class from a namespace that does not exist (Fledge\Async\Future\...), so the FIRST
 * unhandled future error in a process crashed with "Class not found" instead of
 * reporting the actual failure (seen live in the APNs send path on dialed.at).
 */
it('reports an ignored errored future as UnhandledFutureError, not Class-not-found', function () {
    $captured = null;
    $previous = Loop::getErrorHandler();
    Loop::setErrorHandler(function (\Throwable $e) use (&$captured): void {
        $captured = $e;
    });

    try {
        $deferred = new DeferredFuture();
        $deferred->error(new \RuntimeException('boom'));
        unset($deferred); // drop the future without await()/ignore()
        gc_collect_cycles();

        // Give the loop a tick to surface the queued error report.
        Loop::queue(static fn () => null);
        Loop::run();
    } finally {
        Loop::setErrorHandler($previous);
    }

    expect($captured)->toBeInstanceOf(UnhandledFutureError::class)
        ->and($captured->getMessage())->toContain('boom');
});
