<?php declare(strict_types=1);

namespace Fledge\Async\Internal;

use Fledge\Async\ConcurrentIterator;

/**
 * @template T
 *
 * @internal
 */
final class SortOperation implements IntermediateOperation
{
    /**
     * @param \Closure(T, T):int $compare
     */
    public function __construct(private readonly \Closure $compare)
    {
    }

    /**
     * @param ConcurrentIterator<T> $source
     * @return ConcurrentIterator<T>
     */
    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        $values = \iterator_to_array($source, false);
        \usort($values, $this->compare);

        return new ConcurrentArrayIterator($values);
    }
}
