<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Worker;

use Fledge\Async\Parallel\Context\Internal;

final class TaskFailureError extends \Error implements TaskFailureThrowable
{
    use Internal\ContextException;
}
