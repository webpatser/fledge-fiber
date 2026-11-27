<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Parallel\Ipc\IpcHub;
use Fledge\Async\Parallel\Ipc\LocalIpcHub;
use Fledge\Loop\Durations;
use Time\Duration;

final class ProcessContextFactory implements ContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly int $childConnectTimeout;

    /**
     * @param string|null $workingDirectory Working directory.
     * @param array<string, string> $environment Array of environment variables, or use an empty array to inherit from
     *     the parent.
     * @param string|non-empty-list<string>|null $binary Path to PHP binary or array of binary path and options.
     *      Null will attempt to automatically locate the binary.
     * @param Duration|positive-int $childConnectTimeout Time the child will attempt to connect to the parent before
     *      failing, as a `Duration` or in seconds. A `Duration` is rounded up to whole seconds.
     * @param IpcHub $ipcHub Optional IpcHub instance.
     */
    public function __construct(
        private readonly ?string $workingDirectory = null,
        private readonly array $environment = [],
        private readonly string|array|null $binary = null,
        Duration|int $childConnectTimeout = 5,
        private readonly IpcHub $ipcHub = new LocalIpcHub(),
    ) {
        $this->childConnectTimeout = Durations::toWholeSeconds($childConnectTimeout);
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @throws ContextException
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): ProcessContext
    {
        return ProcessContext::start(
            ipcHub: $this->ipcHub,
            script: $script,
            workingDirectory: $this->workingDirectory,
            environment: $this->environment,
            cancellation: $cancellation,
            binary: $this->binary,
            childConnectTimeout: $this->childConnectTimeout,
        );
    }
}
