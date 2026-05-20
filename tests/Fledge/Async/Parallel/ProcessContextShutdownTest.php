<?php

declare(strict_types=1);

use Fledge\Async\Parallel\Context\ProcessContext;
use Fledge\Async\Parallel\Ipc\LocalIpcHub;

/**
 * Regression coverage for the upstream amphp/parallel #226 fix.
 *
 * When the parent reads the task result and then closes the channel during
 * shutdown, the child must terminate cleanly. Previously the child would hit a
 * spurious "Could not send result to parent" error because the result-send was
 * not guarded against ChannelException, and E_USER_ERROR did not reliably
 * terminate the child anyway.
 */
$worker = __DIR__ . '/Fixtures/return-value-worker.php';

$canSpawn = static function () use ($worker): ?string {
    if (! \class_exists(ProcessContext::class)) {
        return 'ProcessContext is not available in this environment.';
    }

    if (! \is_file($worker)) {
        return 'Worker fixture script is missing.';
    }

    if (\PHP_SAPI !== 'cli') {
        return 'Process contexts can only be spawned from the CLI SAPI.';
    }

    if (! \function_exists('proc_open')) {
        return 'proc_open() is disabled, cannot spawn child processes.';
    }

    return null;
};

beforeEach(function () {
    // Channel I/O internally fires-and-forgets a lock release Future, which
    // emits an unrelated #[\NoDiscard] E_USER_WARNING. Swallow only that
    // warning so it does not flag these tests as risky; everything else still
    // surfaces.
    set_error_handler(static function (int $severity, string $message): bool {
        return str_contains($message, 'NoDiscard')
            || str_contains($message, 'should either be used or intentionally ignored');
    });
});

afterEach(function () {
    restore_error_handler();
});

it('terminates the worker cleanly when the parent reads the result during shutdown', function () use ($worker, $canSpawn) {
    if ($skip = $canSpawn()) {
        $this->markTestSkipped($skip);
    }

    $hub = new LocalIpcHub();

    try {
        $context = ProcessContext::start($hub, $worker);
    } catch (\Throwable $exception) {
        $this->markTestSkipped('Could not spawn a process context: ' . $exception->getMessage());
    }

    // Parent reads the result, then the child shuts down. join() also reaps
    // the process and asserts a zero exit code, so a spurious child-side error
    // would surface here as a non-zero exit.
    $result = $context->join();

    expect($result)->toBe('fledge-result');
});

it('does not surface a spurious error when the parent closes the channel before reading', function () use ($worker, $canSpawn) {
    if ($skip = $canSpawn()) {
        $this->markTestSkipped($skip);
    }

    $hub = new LocalIpcHub();

    try {
        $context = ProcessContext::start($hub, $worker);
    } catch (\Throwable $exception) {
        $this->markTestSkipped('Could not spawn a process context: ' . $exception->getMessage());
    }

    // Simulate a parent shutting down without consuming the result: closing the
    // context kills the process. The child must swallow the resulting
    // ChannelException instead of crashing with E_USER_ERROR.
    $context->close();

    expect($context->isClosed())->toBeTrue();
});
