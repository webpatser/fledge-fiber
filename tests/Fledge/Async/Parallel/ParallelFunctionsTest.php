<?php

declare(strict_types=1);

use Fledge\Async\CompositeException;
use Fledge\Async\Parallel\Context\ThreadContext;
use Fledge\Async\Parallel\Worker\ContextWorkerPool;
use Fledge\Async\Parallel\Worker\TaskFailureException;

use function Fledge\Async\Parallel\parallel;
use function Fledge\Async\Parallel\parallelFilter;
use function Fledge\Async\Parallel\parallelMap;

/*
 * parallel(), parallelMap() and parallelFilter() serialize the callable in the parent and run it through
 * Internal\SerializedCallableTask in real worker processes. Closures travel as SerializableClosure, which
 * rebinds them to their class scope in the worker. A closure created inside a test body is scoped to the
 * test case class Pest evaluates at runtime, which the worker cannot autoload, so the closures are created
 * here at file level, static, and passed into the tests.
 */
$triple = static fn (int $value): int => $value * 3;
$isEven = static fn (int $value): bool => $value % 2 === 0;
$fail = static function (int $value): never {
    throw new \RuntimeException("worker failed on {$value}");
};

beforeEach(function () {
    if (ThreadContext::isSupported()) {
        $this->markTestSkipped('ext-parallel is loaded, the worker pool uses threads instead of processes.');
    }

    if (PHP_SAPI !== 'cli' || ! function_exists('proc_open')) {
        $this->markTestSkipped('Process contexts need the CLI SAPI and proc_open().');
    }

    // Channel I/O fires-and-forgets a lock release Future, which emits an unrelated #[\NoDiscard] warning.
    set_error_handler(static function (int $severity, string $message): bool {
        return str_contains($message, 'NoDiscard')
            || str_contains($message, 'should either be used or intentionally ignored');
    });

    $this->pool = new ContextWorkerPool(2);
});

afterEach(function () {
    $this->pool->kill();
    restore_error_handler();
});

it('maps an array with a closure in worker processes', function () use ($triple) {
    $result = parallelMap(['a' => 1, 'b' => 2, 'c' => 3], $triple, $this->pool);

    expect($result)->toBe(['a' => 3, 'b' => 6, 'c' => 9]);
});

it('maps an array with a function name string in worker processes', function () {
    $result = parallelMap(['x' => 'fledge', 'y' => 'fiber'], 'strtoupper', $this->pool);

    expect($result)->toBe(['x' => 'FLEDGE', 'y' => 'FIBER']);
});

it('runs a single call through parallel()', function () {
    $repeat = parallel('str_repeat', $this->pool);

    expect($repeat('ab', 3))->toBe('ababab');
});

it('filters an array with a closure in worker processes', function () use ($isEven) {
    $result = parallelFilter([1, 2, 3, 4, 5, 6], $isEven, pool: $this->pool);

    expect($result)->toBe([1 => 2, 3 => 4, 5 => 6]);
});

it('surfaces an exception thrown by the closure in the parent', function () use ($fail) {
    try {
        parallelMap([1], $fail, $this->pool);

        $this->fail('parallelMap() did not throw.');
    } catch (CompositeException $exception) {
        $reasons = $exception->getReasons();

        expect($reasons)->toHaveCount(1);

        $reason = reset($reasons);

        expect($reason)->toBeInstanceOf(TaskFailureException::class)
            ->and($reason->getOriginalClassName())->toBe(\RuntimeException::class)
            ->and($reason->getOriginalMessage())->toBe('worker failed on 1');
    }
});
