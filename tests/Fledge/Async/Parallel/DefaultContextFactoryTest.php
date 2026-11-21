<?php

declare(strict_types=1);

use Fledge\Async\File\Driver\ParallelFilesystemDriver;
use Fledge\Async\Parallel\Context\DefaultContextFactory;
use Fledge\Async\Parallel\Context\ProcessContext;
use Fledge\Async\Parallel\Context\ThreadContext;
use Fledge\Async\Parallel\Worker\ContextWorkerPool;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;

/*
 * Without ext-parallel, DefaultContextFactory starts a ProcessContext and forwards the child's STDOUT and
 * STDERR to the parent's. Without ext-eio, the default filesystem driver is ParallelFilesystemDriver,
 * whose workers come from DefaultContextFactory; MySQL LOAD DATA LOCAL INFILE (ConnectionProcessor) opens its
 * file through that driver, so a broken factory fails every LOCAL INFILE query in that setup.
 */

$worker = __DIR__.'/Fixtures/return-value-worker.php';

beforeEach(function () {
    if (ThreadContext::isSupported()) {
        $this->markTestSkipped('ext-parallel is loaded, DefaultContextFactory uses threads instead of processes.');
    }

    if (PHP_SAPI !== 'cli' || ! function_exists('proc_open')) {
        $this->markTestSkipped('Process contexts need the CLI SAPI and proc_open().');
    }

    // Channel I/O fires-and-forgets a lock release Future, which emits an unrelated #[\NoDiscard] warning.
    set_error_handler(static function (int $severity, string $message): bool {
        return str_contains($message, 'NoDiscard')
            || str_contains($message, 'should either be used or intentionally ignored');
    });
});

afterEach(function () {
    restore_error_handler();
});

it('starts a process context and returns its result', function () use ($worker) {
    $context = (new DefaultContextFactory)->start($worker, new TimeoutCancellation(10));

    expect($context)->toBeInstanceOf(ProcessContext::class)
        ->and($context->join(new TimeoutCancellation(10)))->toBe('fledge-result');
});

it('reads a file through ParallelFilesystemDriver workers', function () {
    $path = sys_get_temp_dir().'/fledge-default-context-'.bin2hex(random_bytes(4)).'.csv';
    file_put_contents($path, "1,first-row\n2,second-row\n");

    $pool = new ContextWorkerPool(1);
    $driver = new ParallelFilesystemDriver($pool);

    try {
        $contents = async(static function () use ($driver, $path): string {
            $file = $driver->openFile($path, 'r');

            $contents = '';
            while (null !== ($chunk = $file->read(new TimeoutCancellation(10)))) {
                $contents .= $chunk;
            }

            $file->close();

            return $contents;
        })->await(new TimeoutCancellation(20));
    } finally {
        $pool->kill();
        @unlink($path);
    }

    expect($contents)->toBe("1,first-row\n2,second-row\n");
});
