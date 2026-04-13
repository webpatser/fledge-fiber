<?php declare(strict_types=1);

namespace Fledge\Async\Internal;

use Fledge\Async\ConcurrentIterator;

/**
 * @template T
 * @template R
 *
 * @internal
 */
final readonly class FlatMapOperation implements IntermediateOperation
{
    public static function getStopMarker(): object
    {
        static $marker;

        return $marker ??= new \stdClass;
    }

    /**
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(
        private int $bufferSize,
        private int $concurrency,
        private bool $ordered,
        private \Closure $flatMap
    ) {
    }

    public function __invoke(ConcurrentIterator $source): ConcurrentIterator
    {
        if ($this->concurrency === 1) {
            $stop = self::getStopMarker();

            return new ConcurrentIterableIterator((function () use ($source, $stop): iterable {
                foreach ($source as $position => $value) {
                    $iterable = ($this->flatMap)($value, $position);
                    foreach ($iterable as $item) {
                        if ($item === $stop) {
                            return;
                        }

                        yield $item;
                    }
                }
            })(), $this->bufferSize);
        }

        return new ConcurrentFlatMapIterator(
            $source,
            $this->bufferSize,
            $this->concurrency,
            $this->ordered,
            $this->flatMap,
        );
    }
}
