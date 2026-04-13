<?php declare(strict_types=1);

namespace Fledge\Async\Internal;

use Fledge\Async\ConcurrentIterator;

/** @internal */
interface IntermediateOperation
{
    public function __invoke(ConcurrentIterator $source): ConcurrentIterator;
}
