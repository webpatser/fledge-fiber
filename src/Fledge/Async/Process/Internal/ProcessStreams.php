<?php declare(strict_types=1);

namespace Fledge\Async\Process\Internal;

use Fledge\Async\Stream\ReadableResourceStream;
use Fledge\Async\Stream\WritableResourceStream;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/** @internal */
final readonly class ProcessStreams
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        public WritableResourceStream $stdin,
        public ReadableResourceStream $stdout,
        public ReadableResourceStream $stderr,
    ) {
    }
}
