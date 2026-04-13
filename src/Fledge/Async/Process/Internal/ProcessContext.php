<?php declare(strict_types=1);

namespace Fledge\Async\Process\Internal;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/** @internal  */
final readonly class ProcessContext
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        public ProcessHandle $handle,
        public ProcessStreams $streams,
    ) {
    }
}
