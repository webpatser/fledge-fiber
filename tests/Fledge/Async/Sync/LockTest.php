<?php

declare(strict_types=1);

use Fledge\Async\Sync\Lock;

beforeEach(function () {
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (str_contains($message, 'should either be used or intentionally ignored')) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }

        return false;
    });
});

afterEach(function () {
    restore_error_handler();
});

it('does not drop the async release future from the destructor', function () {
    $lock = new Lock(fn () => null);
    unset($lock);

    expect(true)->toBeTrue();
});

it('invokes the release closure when the lock is garbage collected', function () {
    $released = false;
    $lock = new Lock(function () use (&$released) {
        $released = true;
    });
    unset($lock);

    expect($released)->toBeTrue();
});
