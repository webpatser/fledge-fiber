<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Worker;

use Fledge\Async\Parallel\Context\Internal;

final class TaskFailureException extends \Exception implements TaskFailureThrowable
{
    use Internal\ContextException;
}
