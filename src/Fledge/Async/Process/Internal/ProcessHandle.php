<?php declare(strict_types=1);

namespace Fledge\Async\Process\Internal;

use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/** @internal */
abstract class ProcessHandle
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var resource */
    private $proc;

    /** @var DeferredFuture<int> */
    public readonly DeferredFuture $joinDeferred;

    public readonly int $originalParentPid;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var positive-int
     */
    public int $pid;

    public ProcessStatus $status = ProcessStatus::Starting;

    /**
     * @param resource $proc
     */
    public function __construct($proc)
    {
        $this->proc = $proc;
        $this->joinDeferred = new DeferredFuture;
        $this->originalParentPid = \getmypid();
    }

    abstract public function wait(): void;
}
