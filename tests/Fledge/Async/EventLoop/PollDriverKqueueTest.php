<?php

declare(strict_types=1);

namespace Tests\Fledge\Async\EventLoop;

use Io\Poll\Backend;

require_once __DIR__ . '/AbstractPollDriverTest.php';

final class PollDriverKqueueTest extends AbstractPollDriverTest
{
    protected function backend(): Backend
    {
        return Backend::Kqueue;
    }
}
