<?php

declare(strict_types=1);

use Fledge\Async\Cancellation;
use Fledge\Async\File\Driver\ParallelFilesystemDriver;
use Fledge\Async\Parallel\Worker\Execution;
use Fledge\Async\Parallel\Worker\LimitedWorkerPool;
use Fledge\Async\Parallel\Worker\Task;
use Fledge\Async\Parallel\Worker\Worker;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * This tests the worker limit logic of selectWorker() against an in-memory pool, so worker usage is counted
 * deterministically without spawning child processes: getWorker() suspends like a real worker start, and its
 * workers never run tasks. The pool stands in for the child processes only.
 */

function limitTestWorker(): Worker
{
    return new class implements Worker
    {
        public function isRunning(): bool
        {
            return true;
        }

        public function isIdle(): bool
        {
            return true;
        }

        public function submit(Task $task, ?Cancellation $cancellation = null): Execution
        {
            throw new LogicException('not used');
        }

        public function shutdown(): void {}

        public function kill(): void {}
    };
}

function limitTestPool(int $limit, ?Closure $afterStart = null): LimitedWorkerPool
{
    return new class($limit, $afterStart) implements LimitedWorkerPool
    {
        public int $started = 0;

        public function __construct(private int $limit, private ?Closure $afterStart) {}

        public function getWorkerLimit(): int
        {
            return $this->limit;
        }

        public function getWorker(): Worker
        {
            delay(0.01); // A worker start suspends.

            $this->started++;

            if ($this->afterStart !== null) {
                $hook = $this->afterStart;
                $this->afterStart = null;
                $hook(); // Runs right before the request's future completes.
            }

            return limitTestWorker();
        }

        public function getWorkerCount(): int
        {
            return $this->started;
        }

        public function getIdleWorkerCount(): int
        {
            return 0;
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function isIdle(): bool
        {
            return true;
        }

        public function submit(Task $task, ?Cancellation $cancellation = null): Execution
        {
            throw new LogicException('not used');
        }

        public function shutdown(): void {}

        public function kill(): void {}
    };
}

function selectLimitTestWorker(ParallelFilesystemDriver $driver): Worker
{
    return (fn (): Worker => $this->selectWorker())->call($driver);
}

function limitTestStoredWorkers(ParallelFilesystemDriver $driver): int
{
    return (fn (): int => $this->workerStorage->count())->call($driver);
}

/** Fails the test instead of spinning for ever when a loop never yields. */
function limitTestWatchdog(Closure $body): mixed
{
    if (! function_exists('pcntl_alarm')) {
        return $body();
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): never {
        throw new RuntimeException('watchdog: the code under test did not finish within 10 seconds');
    });
    pcntl_alarm(10);

    try {
        return $body();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}

it('does not start a worker beyond the limit for a caller arriving as a request completes', function () {
    $late = null;
    $driver = null;

    // The late caller starts right before the first request's future completes, so it runs while that
    // request is complete but its fiber has not resumed yet.
    $pool = limitTestPool(1, function () use (&$late, &$driver): void {
        $late = async(fn () => selectLimitTestWorker($driver));
    });
    $driver = new ParallelFilesystemDriver($pool);

    // The driver keeps its workers in a WeakMap, so the test holds them as a file handle would.
    $workers = limitTestWatchdog(function () use ($driver, &$late): array {
        $first = async(fn () => selectLimitTestWorker($driver));
        $waiting = async(fn () => selectLimitTestWorker($driver));

        return [$first->await(), $waiting->await(), $late->await()];
    });

    expect($workers)->toHaveCount(3)
        ->and($pool->started)->toBe(1)
        ->and(limitTestStoredWorkers($driver))->toBe(1);
});

it('starts one worker at a time while several callers wait for a pending request', function () {
    $pool = limitTestPool(2);
    $driver = new ParallelFilesystemDriver($pool);

    $workers = limitTestWatchdog(function () use ($driver): array {
        $callers = [];
        foreach (range(1, 5) as $_) {
            $callers[] = async(fn () => selectLimitTestWorker($driver));
        }

        return array_map(fn ($caller) => $caller->await(), $callers);
    });

    expect($workers)->toHaveCount(5)
        ->and($pool->started)->toBe(2)
        ->and(limitTestStoredWorkers($driver))->toBe(2);
});
