<?php

declare(strict_types=1);

use Fledge\Async\CancelledException;
use Fledge\Async\CompositeCancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Future;
use Fledge\Async\Interval;
use Revolt\EventLoop;

use function Fledge\Async\async;
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

// Scheduling guards: await() keeps the round trip through the loop, so callbacks queued before an await
// run first and waiters resume in subscription and completion order.

it('still runs earlier queued callbacks when the main context awaits a completed future', function () {
    $ran = 0;
    $future = Future::complete('value');
    EventLoop::queue(static function () use (&$ran): void {
        $ran++;
    });

    expect($future->await())->toBe('value')
        ->and($ran)->toBe(1);
});

it('still suspends on a pending future and resumes waiters in subscription order', function () {
    $ran = 0;
    $order = [];
    $deferred = new DeferredFuture();
    $future = $deferred->getFuture();

    $first = async(static function () use ($future, &$order, &$ran): void {
        EventLoop::queue(static function () use (&$ran): void {
            $ran++;
        });
        $order[] = 'first:'.$future->await().':'.$ran;
    });
    $second = async(static function () use ($future, &$order): void {
        $order[] = 'second:'.$future->await();
    });

    EventLoop::queue(static fn () => $deferred->complete('v'));

    $first->await();
    $second->await();

    expect($order)->toBe(['first:v:1', 'second:v']);
});

it('keeps the completion order of Future\await, awaitAll, awaitAny, awaitFirst and iterate', function () {
    $result = async(static function (): array {
        $late = new DeferredFuture();
        $early = new DeferredFuture();
        $futures = ['late' => $late->getFuture(), 'done' => Future::complete('d'), 'early' => $early->getFuture()];

        EventLoop::queue(static fn () => $early->complete('e'));
        EventLoop::defer(static fn () => $late->complete('l'));

        $iterated = [];
        foreach (Future::iterate($futures) as $key => $future) {
            $iterated[] = $key.'='.$future->await();
        }

        $make = static function (): array {
            $late = new DeferredFuture();
            $early = new DeferredFuture();
            EventLoop::queue(static fn () => $early->complete('e'));
            EventLoop::defer(static fn () => $late->complete('l'));

            return ['late' => $late->getFuture(), 'early' => $early->getFuture()];
        };

        $failing = new DeferredFuture();
        EventLoop::queue(static fn () => $failing->error(new RuntimeException('x')));

        return [
            'iterate' => $iterated,
            'await' => Future\await($make()),
            'awaitAll' => Future\awaitAll($make())[1],
            'awaitFirst' => Future\awaitFirst($make()),
            'awaitAny' => Future\awaitAny([$failing->getFuture(), ...array_values($make())]),
        ];
    })->await();

    expect($result)->toBe([
        'iterate' => ['done=d', 'early=e', 'late=l'],
        'await' => ['early' => 'e', 'late' => 'l'],
        'awaitAll' => ['early' => 'e', 'late' => 'l'],
        'awaitFirst' => 'e',
        'awaitAny' => 'e',
    ]);
});
