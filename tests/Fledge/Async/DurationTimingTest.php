<?php

declare(strict_types=1);

use Fledge\Async\CancelledException;
use Fledge\Async\Interval;
use Fledge\Async\Pipeline;
use Fledge\Async\TimeoutCancellation;
use Time\Duration;

use function Fledge\Async\delay;

describe('TimeoutCancellation', function () {
    it('accepts a Duration', function () {
        $cancellation = new TimeoutCancellation(Duration::fromMilliseconds(10));

        expect($cancellation->isRequested())->toBeFalse();

        delay(0.05);

        expect($cancellation->isRequested())->toBeTrue()
            ->and(fn () => $cancellation->throwIfRequested())->toThrow(CancelledException::class);
    });

    it('rejects a negated Duration like a negative float', function (Duration|float $timeout) {
        expect(fn () => new TimeoutCancellation($timeout))
            ->toThrow(\Error::class, 'Delay must be greater than or equal to zero');
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);

    it('keeps float seconds unchanged', function () {
        $cancellation = new TimeoutCancellation(0.01);

        delay(0.05);

        expect($cancellation->isRequested())->toBeTrue();
    });

    it('never cancels with float INF', function () {
        $cancellation = new TimeoutCancellation(\INF);

        delay(0.02);

        expect($cancellation->isRequested())->toBeFalse();
    });
});

describe('delay()', function () {
    it('accepts a Duration', function () {
        $start = \hrtime(true);

        delay(Duration::fromMilliseconds(20));

        expect((\hrtime(true) - $start) / 1e6)->toBeGreaterThanOrEqual(19.0);
    });

    it('rejects a negated Duration like a negative float', function (Duration|float $timeout) {
        expect(fn () => delay($timeout))
            ->toThrow(\Error::class, 'Delay must be greater than or equal to zero');
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);
});

describe('Interval', function () {
    it('accepts a Duration', function () {
        $ticks = 0;
        $interval = new Interval(Duration::fromMilliseconds(10), function () use (&$ticks): void {
            ++$ticks;
        });

        delay(0.055);
        $interval->disable();

        expect($ticks)->toBeGreaterThanOrEqual(2);
    });

    it('rejects a negated Duration like a negative float', function (Duration|float $interval) {
        expect(fn () => new Interval($interval, static fn () => null))
            ->toThrow(\Error::class, 'Interval must be greater than or equal to zero');
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);
});

describe('Pipeline::delay()', function () {
    it('accepts a Duration', function () {
        $start = \hrtime(true);

        $values = Pipeline::fromIterable([1, 2])->delay(Duration::fromMilliseconds(10))->toArray();

        expect($values)->toBe([1, 2])
            ->and((\hrtime(true) - $start) / 1e6)->toBeGreaterThanOrEqual(19.0);
    });

    it('rejects a negated Duration like a negative float', function (Duration|float $delay) {
        expect(fn () => Pipeline::fromIterable([1])->delay($delay)->toArray())
            ->toThrow(\Error::class, 'Delay must be greater than or equal to zero');
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);
});
