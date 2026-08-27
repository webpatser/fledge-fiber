<?php

use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\RetryPolicy;

it('reproduces the historic reconnect backoff by default', function () {
    $backoff = BackoffStrategy::default();

    expect($backoff->delay(1))->toBe(0.1)
        ->and($backoff->delay(2))->toBe(0.2)
        ->and($backoff->delay(3))->toBe(0.4)
        ->and($backoff->delay(4))->toBe(0.8)
        ->and($backoff->delay(5))->toBe(1.0)
        ->and($backoff->delay(50))->toBe(1.0);
});

it('returns the base for the constant algorithm', function () {
    $backoff = new BackoffStrategy('constant', 0.25);

    expect($backoff->delay(1))->toBe(0.25)
        ->and($backoff->delay(9))->toBe(0.25);
});

it('doubles and caps for the exponential algorithm', function () {
    $backoff = new BackoffStrategy('exponential', 0.1, 0.5);

    expect($backoff->delay(1))->toBe(0.2)
        ->and($backoff->delay(2))->toBe(0.4)
        ->and($backoff->delay(3))->toBe(0.5)
        ->and($backoff->delay(30))->toBe(0.5);
});

it('keeps uniform delays within the base', function () {
    $backoff = new BackoffStrategy('uniform', 0.2);

    foreach (range(1, 25) as $attempt) {
        $delay = $backoff->delay($attempt);

        expect($delay)->toBeGreaterThanOrEqual(0.0)
            ->and($delay)->toBeLessThanOrEqual(0.2);
    }
});

it('keeps full jitter delays within the exponential envelope', function () {
    $backoff = new BackoffStrategy('full_jitter', 0.1, 0.5);

    foreach (range(1, 25) as $attempt) {
        $delay = $backoff->delay($attempt);

        expect($delay)->toBeGreaterThanOrEqual(0.0)
            ->and($delay)->toBeLessThanOrEqual(0.5);
    }
});

it('keeps equal jitter delays in the upper half of the envelope', function () {
    $backoff = new BackoffStrategy('equal_jitter', 0.1, 0.8);

    foreach (range(3, 25) as $attempt) {
        $delay = $backoff->delay($attempt);

        expect($delay)->toBeGreaterThanOrEqual(0.4)
            ->and($delay)->toBeLessThanOrEqual(0.8);
    }
});

it('keeps decorrelated jitter delays between base and cap', function () {
    $backoff = new BackoffStrategy('decorrelated_jitter', 0.05, 1.0);

    $previous = null;

    foreach (range(1, 25) as $attempt) {
        $previous = $backoff->delay($attempt, $previous);

        expect($previous)->toBeGreaterThanOrEqual(0.05)
            ->and($previous)->toBeLessThanOrEqual(1.0);
    }
});

it('builds from a retry policy with backoff_base overriding retry_interval', function () {
    $backoff = BackoffStrategy::fromRetryPolicy(new RetryPolicy(
        retryIntervalSeconds: 0.5,
        backoffAlgorithm: 'constant',
        backoffBase: 0.2,
        backoffCap: 3.0,
    ));

    expect($backoff->algorithm)->toBe('constant')
        ->and($backoff->base)->toBe(0.2)
        ->and($backoff->cap)->toBe(3.0);
});

it('falls back to retry_interval as the base', function () {
    $backoff = BackoffStrategy::fromRetryPolicy(new RetryPolicy(retryIntervalSeconds: 0.5));

    expect($backoff->base)->toBe(0.5)
        ->and($backoff->cap)->toBe(1.0)
        ->and($backoff->algorithm)->toBe(RetryPolicy::BACKOFF_DEFAULT);
});

it('rejects an unknown algorithm', function () {
    new BackoffStrategy('warp');
})->throws(InvalidArgumentException::class, 'not a valid backoff algorithm');
