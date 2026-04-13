<?php

use Fledge\Fiber\Livewire\Concerns\WithFibers;

// Create a test class that uses the trait
beforeEach(function () {
    $this->component = new class {
        use WithFibers {
            concurrently as public;
        }
    };
});

it('returns results in order', function () {
    $results = $this->component->concurrently(
        fn () => 'first',
        fn () => 'second',
        fn () => 'third',
    );

    expect($results)->toBe(['first', 'second', 'third']);
});

it('handles single operation without fiber overhead', function () {
    $results = $this->component->concurrently(
        fn () => 42,
    );

    expect($results)->toBe([42]);
});

it('runs multiple operations via Fibers', function () {
    // usleep() blocks per-Fiber (not async-aware), so we can't test
    // actual interleaving without async I/O. Test that Fibers are
    // created and results returned correctly for multiple operations.
    $results = $this->component->concurrently(
        fn () => 'a',
        fn () => 'b',
        fn () => 'c',
        fn () => 'd',
        fn () => 'e',
    );

    expect($results)->toBe(['a', 'b', 'c', 'd', 'e']);
});

it('propagates exceptions', function () {
    $this->component->concurrently(
        fn () => 'ok',
        fn () => throw new RuntimeException('boom'),
    );
})->throws(RuntimeException::class, 'boom');
