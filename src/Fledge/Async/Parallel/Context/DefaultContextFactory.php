<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context;

use Fledge\Async\Stream;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Parallel\Ipc\IpcHub;
use Fledge\Async\Parallel\Ipc\LocalIpcHub;
use function Fledge\Async\async;

final class DefaultContextFactory implements ContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly ContextFactory $contextFactory;

    /**
     * @param IpcHub $ipcHub Optional IpcHub instance.
     */
    public function __construct(IpcHub $ipcHub = new LocalIpcHub())
    {
        if (ThreadContext::isSupported()) {
            $this->contextFactory = new ThreadContextFactory(ipcHub: $ipcHub);
        } else {
            $this->contextFactory = new ProcessContextFactory(ipcHub: $ipcHub);
        }
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @throws ContextException
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context
    {
        $context = $this->contextFactory->start($script, $cancellation);

        if ($context instanceof ProcessContext) {
            $stdout = $context->getStdout();
            $stdout->unreference();

            $stderr = $context->getStderr();
            $stderr->unreference();

            async(ByteStream\pipe(...), $stdout, ByteStream\getStdout())->ignore();
            async(ByteStream\pipe(...), $stderr, ByteStream\getStderr())->ignore();
        }

        return $context;
    }
}
