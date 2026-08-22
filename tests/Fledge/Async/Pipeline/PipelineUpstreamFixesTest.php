<?php

declare(strict_types=1);

use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\ConcurrentIterator;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\DisposedException;
use Fledge\Async\Internal\ConcurrentArrayIterator;
use Fledge\Async\Internal\ConcurrentChainedIterator;
use Fledge\Async\Internal\ConcurrentClosureIterator;
use Fledge\Async\Pipeline;
use Fledge\Async\Queue;
use Fledge\Async\TimeoutCancellation;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

it('reports completion correctly from a chained iterator', function () {
    $iterator = new ConcurrentChainedIterator([
        new ConcurrentArrayIterator([1, 2, 3]),
        new ConcurrentArrayIterator([4, 5]),
    ]);

    expect($iterator->isComplete())->toBeFalse();

    while ($iterator->continue()) {
        expect($iterator->isComplete())->toBeFalse();
    }

    expect($iterator->isComplete())->toBeTrue();
});

it('orders concurrent continues while the source awaits the sequence', function () {
    $invocations = 0;
    $iterator = new ConcurrentClosureIterator(function () use (&$invocations): int {
        $i = $invocations++;
        if ($i === 0) {
            delay(0.1); // First invocation is slow, subsequent invocations return immediately.
        }

        return $i;
    });

    $consume = static fn () => $iterator->continue() ? $iterator->getValue() : null;

    $futures = [];
    $futures[] = async($consume);
    $futures[] = async($consume);

    delay(0.05); // Allow the second supplier fiber to block on the ordering sequence.

    $futures[] = async($consume);

    expect(await($futures))->toBe([0, 1, 2])
        ->and($invocations)->toBe(3);
});

it('consumes a value when cancellation occurs in the same tick as delivery', function () {
    $invocations = 0;
    $iterator = new ConcurrentClosureIterator(function () use (&$invocations): int {
        return $invocations++;
    });

    $deferredCancellation = new DeferredCancellation();

    $future = async(static fn () => $iterator->continue($deferredCancellation->getCancellation())
        ? $iterator->getValue()
        : null);

    // Cancellation callbacks are queued in the same tick the first value is delivered.
    EventLoop::queue(static fn () => $deferredCancellation->cancel());

    expect($future->await())->toBe(0);

    expect($iterator->continue(new TimeoutCancellation(1)))->toBeTrue()
        ->and($iterator->getValue())->toBe(1);
});

it('delivers the pending value after consecutive cancelled continues', function () {
    $invocations = 0;
    $iterator = new ConcurrentClosureIterator(function () use (&$invocations): int {
        $i = $invocations++;

        delay(0.5);

        return $i;
    });

    expect(fn () => $iterator->continue(new TimeoutCancellation(0.1)))
        ->toThrow(CancelledException::class);

    expect(fn () => $iterator->continue(new TimeoutCancellation(0.1)))
        ->toThrow(CancelledException::class);

    expect($iterator->continue())->toBeTrue()
        ->and($iterator->getValue())->toBe(0)
        ->and($invocations)->toBe(1);
});

it('completes a generated iterator disposed while the source awaits backpressure', function () {
    $iterator = new ConcurrentClosureIterator(function (): int {
        delay(0.5);

        return 1;
    });

    expect(fn () => $iterator->continue(new TimeoutCancellation(0.1)))
        ->toThrow(CancelledException::class);

    delay(0.1); // Allow the supplier fiber to suspend within push() awaiting backpressure.

    $iterator->dispose();

    delay(0.1); // Allow the supplier fiber to be resumed with the disposal exception.

    expect($iterator->isComplete())->toBeTrue();
});

it('completes a generated iterator disposed before any value is consumed', function () {
    $iterator = new ConcurrentClosureIterator(fn () => 1);

    $iterator->dispose();

    delay(0.01); // Allow the cancellation callback to run on the event loop.

    expect($iterator->isComplete())->toBeTrue();
});

it('completes a generated iterator disposed after a value was consumed', function () {
    $iterator = new ConcurrentClosureIterator(function (): int {
        static $i = 0;

        return ++$i;
    });

    expect($iterator->continue())->toBeTrue()
        ->and($iterator->getValue())->toBe(1);

    $iterator->dispose();

    delay(0.01); // Allow the cancellation callback to run on the event loop.

    expect($iterator->isComplete())->toBeTrue();
});

it('keeps ordering with an ordered concurrent pipeline over an async source', function () {
    $range = range(0, 99);

    $queue = new Queue();

    EventLoop::queue(function () use ($queue): void {
        foreach (array_chunk(range(0, 99), 10) as $chunk) {
            array_map($queue->pushAsync(...), $chunk);
            delay(0.1);
        }

        $queue->complete();
    });

    $emitted = [];

    Pipeline::fromIterable($queue->iterate())
        ->concurrent(7)
        ->forEach(function (int $value) use (&$emitted): void {
            $emitted[] = $value;
        });

    expect($emitted)->toBe($range);
});

it('throws the queue error to remaining consumers after another iterator is disposed', function () {
    $queue = new Queue();

    $iteratorA = $queue->iterate();
    $iteratorB = $queue->iterate();

    $queue->error($exception = new Exception('Queue failed'));

    $iteratorA->dispose();

    try {
        $iteratorB->continue();
        expect(false)->toBeTrue('Expected exception to be thrown');
    } catch (Exception $caught) {
        expect($caught)->toBe($exception);
    }
});

it('delivers buffered values before the error and relieves backpressure', function () {
    $queue = new Queue();

    $future = $queue->pushAsync(1);

    $queue->error($exception = new Exception('Queue failed'));

    // Producer waits until the value is consumed, even after the queue errors.
    expect($future->isComplete())->toBeFalse();

    $iterator = $queue->iterate();

    // Values enqueued before the error are delivered before the error is thrown.
    expect($iterator->continue())->toBeTrue()
        ->and($iterator->getValue())->toBe(1);

    expect($future->await())->toBeNull();

    try {
        $iterator->continue();
        expect(false)->toBeTrue('Expected exception to be thrown');
    } catch (Exception $caught) {
        expect($caught)->toBe($exception);
    }
});

it('relieves pending backpressure of an errored queue on disposal', function () {
    $queue = new Queue();

    $future = $queue->pushAsync(1);

    $queue->error(new Exception('Queue failed'));

    $iterator = $queue->iterate();
    $iterator->dispose();

    // Disposal relieves the pending backpressure of an errored queue.
    expect(fn () => $future->await())->toThrow(DisposedException::class);
});

it('continues delivering buffered values after completion when another iterator is disposed', function () {
    $queue = new Queue(2);
    $queue->pushAsync(1)->ignore();
    $queue->pushAsync(2)->ignore();
    $queue->complete();

    $iteratorA = $queue->iterate();
    $iteratorB = $queue->iterate();

    $iteratorA->dispose();

    expect($iteratorB->continue())->toBeTrue()
        ->and($iteratorB->getValue())->toBe(1);

    expect($iteratorB->continue())->toBeTrue()
        ->and($iteratorB->getValue())->toBe(2);

    expect($iteratorB->continue())->toBeFalse();
});

it('returns correct positions when a waiting consumer in the middle is cancelled', function () {
    // Upstream amphp/pipeline issue #23.
    $expected = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

    $queue = new Queue();
    $iterator = $queue->iterate();
    $deferredCancellation = new DeferredCancellation();

    $results = [];

    $consume = function (
        ConcurrentIterator $iterator,
        ?Cancellation $cancellation = null,
    ) use (&$results): void {
        while ($iterator->continue($cancellation)) {
            $results[$iterator->getPosition()] = $iterator->getValue();
        }
    };

    $deferredCancellation->getCancellation()->subscribe(
        function () use ($queue, $expected): void {
            foreach ($expected as $value) {
                $queue->push($value);
            }
            $queue->complete();
        },
    );

    await([
        async(fn () => $consume($iterator)),
        async(function () use ($consume, $iterator, $deferredCancellation): void {
            try {
                $consume($iterator, $deferredCancellation->getCancellation());
                expect(false)->toBeTrue('Expected cancellation exception');
            } catch (Throwable) {
                // Cancellation expected.
            }
        }),
        async(function () use ($consume, $iterator, $deferredCancellation): void {
            $deferredCancellation->cancel();
            $consume($iterator);
        }),
    ]);

    expect($results)->toEqual($expected);
});

it('does not complete the take queue more than once with unordered concurrency', function () {
    $size = 50;

    // The stop marker completes the underlying queue; with multiple unordered
    // coroutines this must not complete the queue more than once.
    $values = Pipeline::fromIterable(range(1, 100))
        ->concurrent(4)
        ->unordered()
        ->take($size)
        ->toArray();

    expect($values)->toHaveCount($size);
});

it('does not complete the takeWhile queue more than once with unordered concurrency', function (int $size) {
    $values = Pipeline::fromIterable(range(1, 100))
        ->concurrent(4)
        ->unordered()
        ->takeWhile(fn (int $value) => $value <= $size)
        ->toArray();

    expect($values)->toHaveCount($size);
})->with(array_combine(
    array_map(fn (int $size) => "take-{$size}", range(10, 100, 10)),
    array_map(fn (int $size) => [$size], range(10, 100, 10)),
));
