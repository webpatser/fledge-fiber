<?php

declare(strict_types=1);

use Fledge\Loop;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\DriverFactory;

/*
 * Guard: the suite must really run on the driver FLEDGE_EVENT_LOOP asks for (the CI matrix legs poll and select,
 * and auto when the variable is unset). The loop's lazily created default driver reads the variable itself.
 */
test('the suite runs on the event loop driver selected by FLEDGE_EVENT_LOOP', function () {
    $expected = match (DriverFactory::resolve()) {
        'poll' => PollDriver::class,
        'select' => StreamSelectDriver::class,
    };

    expect(Loop::getDriver())->toBeInstanceOf($expected);
});
