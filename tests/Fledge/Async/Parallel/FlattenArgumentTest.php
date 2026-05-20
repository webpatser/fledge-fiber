<?php

declare(strict_types=1);

use function Fledge\Async\Parallel\Context\flattenArgument;

beforeEach(function () {
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
});

afterEach(function () {
    restore_error_handler();
});

it('renders NAN without emitting a PHP warning', function () {
    expect(flattenArgument(NAN))->toBe('NAN');
});

it('renders the result of sqrt(-1) as NAN', function () {
    expect(flattenArgument(sqrt(-1)))->toBe('NAN');
});

it('still casts ordinary floats to string', function () {
    expect(flattenArgument(1.5))->toBe('1.5');
});
