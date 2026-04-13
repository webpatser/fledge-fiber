<?php declare(strict_types=1);

namespace Fledge\Async\Process\Internal;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/** @internal */
final readonly class ProcHolder
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        public ProcessRunner $runner,
        public ProcessHandle $handle,
    ) {
    }

    public function __destruct()
    {
        $this->runner->destroy($this->handle);
    }
}
