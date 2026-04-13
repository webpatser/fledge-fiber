<?php declare(strict_types=1);

namespace Fledge\Async\Process\Internal\Windows;

use Fledge\Async\Stream\ReadableResourceStream;
use Fledge\Async\Process\Internal\ProcessHandle;
use Fledge\Async\Sync\Barrier;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class WindowsHandle extends ProcessHandle
{
    public readonly Barrier $startBarrier;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ReadableResourceStream $exitCodeStream;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $wrapperPid;

    /** @var resource[] */
    public array $sockets = [];

    /** @var string[] */
    public array $securityTokens = [];

    /**
     * @param resource $proc
     */
    public function __construct($proc)
    {
        parent::__construct($proc);

        $this->startBarrier = new Barrier(4);
    }

    public function wait(): void
    {
        // Nothing to do.
    }
}
