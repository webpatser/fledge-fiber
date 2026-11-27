<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Parallel\Ipc\IpcHub;
use Fledge\Async\Parallel\Ipc\LocalIpcHub;
use Fledge\Loop\Durations;
use Time\Duration;

final class ThreadContextFactory implements ContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly int $childConnectTimeout;

    /**
     * @param Duration|positive-int $childConnectTimeout Time the child will attempt to connect to the parent before
     *      failing, as a `Duration` or in seconds. A `Duration` is rounded up to whole seconds.
     * @param IpcHub $ipcHub Optional IpcHub instance.
     */
    public function __construct(
        Duration|int $childConnectTimeout = 5,
        private readonly IpcHub $ipcHub = new LocalIpcHub(),
    ) {
        $this->childConnectTimeout = Durations::toWholeSeconds($childConnectTimeout);
    }

    public function start(array|string $script, ?Cancellation $cancellation = null): ThreadContext
    {
        return ThreadContext::start($this->ipcHub, $script, $cancellation, $this->childConnectTimeout);
    }
}
