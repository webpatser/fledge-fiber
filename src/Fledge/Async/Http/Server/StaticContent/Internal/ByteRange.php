<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\StaticContent\Internal;

/**
 * Used for range array in {@see ByteRangeRequest}.
 *
 * @internal
 */
final class ByteRange
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
    ) {
    }
}
