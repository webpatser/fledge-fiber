<?php

declare(strict_types=1);

namespace Tests\Fledge\Async\EventLoop;

use Fledge\Async\EventLoop\Driver\PollDriver;
use Io\Poll\Backend;

require_once __DIR__ . '/DriverTest.php';
require_once __DIR__ . '/PollDriverTargetedTests.php';

/**
 * Runs Revolt's driver suite plus the targeted tests against PollDriver on one Io\Poll backend.
 * Concrete subclasses name the backend; the whole class is skipped where that backend is unavailable.
 */
abstract class AbstractPollDriverTest extends DriverTest
{
    use PollDriverTargetedTests;

    abstract protected function backend(): Backend;

    public function getFactory(): callable
    {
        $backend = $this->backend();

        return static fn (): PollDriver => new PollDriver($backend);
    }

    public function setUp(): void
    {
        if (!PollDriver::isSupported()) {
            self::markTestSkipped('PollDriver is not supported on this build');
        }

        if (!$this->backend()->isAvailable()) {
            self::markTestSkipped('Io\Poll backend ' . $this->backend()->name . ' is not available on this platform');
        }

        parent::setUp();
    }
}
