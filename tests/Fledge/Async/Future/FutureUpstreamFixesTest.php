<?php

declare(strict_types=1);

use Fledge\Async\CancelledException;
use Fledge\Async\CompositeCancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\Future;
use Fledge\Async\Interval;

use function Fledge\Async\delay;

it('reports a composite cancellation as requested immediately after cancellation', function () {
    $deferredCancellation = new DeferredCancellation();
    $compositeCancellation = new CompositeCancellation($deferredCancellation->getCancellation());

    $deferredCancellation->cancel();

    expect($deferredCancellation->getCancellation()->isRequested())->toBeTrue()
        ->and($compositeCancellation->isRequested())->toBeTrue();
});

it('throws from a composite cancellation immediately after cancellation', function () {
    $deferredCancellation = new DeferredCancellation();
    $compositeCancellation = new CompositeCancellation($deferredCancellation->getCancellation());

    $deferredCancellation->cancel($previous = new Exception());

    try {
        $compositeCancellation->throwIfRequested();
        expect(false)->toBeTrue('Expected '.CancelledException::class.' to be thrown');
    } catch (CancelledException $exception) {
        expect($exception->getPrevious())->toBe($previous);
    }
});

it('stops consuming the source when the iterable from Future::iterate() is abandoned', function () {
    $count = 0;

    /** @var Generator<int, Future<int>, void, void> $generator */
    $generator = (static function () use (&$count): Generator {
        while (true) {
            yield Future::complete(++$count);
            delay(0.01);
        }
    })();

    foreach (Future::iterate($generator) as $future) {
        break; // Abandon the iterator after the first item.
    }

    // Wait several generator periods; the generator must not be consumed further
    // once the abandonment is discovered.
    delay(0.1);

    expect($count)->toBe(2);
});

it('forbids cloning an interval', function () {
    $interval = new Interval(0.1, static fn () => null);

    expect(fn () => clone $interval)->toThrow(Error::class);

    $interval->disable();
});

it('forbids serializing an interval', function () {
    $interval = new Interval(0.1, static fn () => null);

    expect(fn () => serialize($interval))->toThrow(Error::class);

    $interval->disable();
});
