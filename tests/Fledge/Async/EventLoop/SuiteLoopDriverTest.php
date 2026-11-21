<?php

declare(strict_types=1);

use Fledge\Async\EventLoop\Driver\PollDriver;
use Fledge\Async\EventLoop\LoopDriver;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

/*
 * Guard: when the CI leg sets FLEDGE_EVENT_LOOP, the suite must really run on that driver. Fails loudly if the
 * bootstrap in tests/helpers.php stops installing it.
 */
$env = getenv(LoopDriver::ENV);
$resolved = is_string($env) && trim($env) !== '' ? LoopDriver::resolve() : 'revolt';

test('the suite runs on the event loop driver selected by FLEDGE_EVENT_LOOP', function () use ($resolved) {
    $expected = match ($resolved) {
        'poll' => PollDriver::class,
        'select' => StreamSelectDriver::class,
    };

    expect(EventLoop::getDriver())->toBeInstanceOf($expected);
})->skip($resolved === 'revolt', 'FLEDGE_EVENT_LOOP is unset or resolves to revolt');
