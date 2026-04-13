<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context\Internal;

use Fledge\Async\Parallel\Context\ContextException;

/**
 * @internal
 * @template-covariant TValue
 */
interface ExitResult
{
    /**
     * @return TValue Return value of the callable given to the execution context.
     *
     * @throws ContextException If the context exited with an uncaught exception.
     */
    public function getResult(): mixed;
}
