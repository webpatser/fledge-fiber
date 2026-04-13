<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Worker\Internal;

use Fledge\Async\CancelledException;
use Fledge\Async\Parallel\Worker\TaskCancelledException;

/** @internal */
final class TaskCancelled extends TaskFailure
{
    public function __construct(string $id, CancelledException $exception)
    {
        parent::__construct($id, $exception);
    }

    /**
     * @throws TaskCancelledException
     */
    public function getResult(): never
    {
        throw new TaskCancelledException($this->createException());
    }
}
