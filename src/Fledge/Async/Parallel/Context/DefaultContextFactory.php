<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context;

use Fledge\Async\Stream;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Parallel\Ipc\IpcHub;
use Fledge\Async\Parallel\Ipc\LocalIpcHub;
use Revolt\EventLoop;
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

            async(self::pipe(...), $stdout, self::outputStream('stdout'))->ignore();
            async(self::pipe(...), $stderr, self::outputStream('stderr'))->ignore();
        }

        return $context;
    }

    /**
     * Forwards all data from the child's output stream to the parent's until the child closes it.
     */
    private static function pipe(Stream\ReadableStream $source, Stream\WritableStream $destination): void
    {
        while (null !== $chunk = $source->read()) {
            $destination->write($chunk);
        }
    }

    /**
     * Parent STDOUT or STDERR, one stream per event loop driver so the resource is never freed and reopened.
     *
     * @param 'stdout'|'stderr' $name
     */
    private static function outputStream(string $name): Stream\WritableResourceStream
    {
        /** @var array<string, \WeakMap<EventLoop\Driver, Stream\WritableResourceStream>> $map */
        static $map = [];
        $map[$name] ??= new \WeakMap();

        $driver = EventLoop::getDriver();

        if (!isset($map[$name][$driver])) {
            $constant = \strtoupper($name);
            $resource = \defined($constant) ? \constant($constant) : \fopen('php://' . $name, 'wb');
            $map[$name][$driver] = new Stream\WritableResourceStream($resource);
        }

        return $map[$name][$driver];
    }
}
