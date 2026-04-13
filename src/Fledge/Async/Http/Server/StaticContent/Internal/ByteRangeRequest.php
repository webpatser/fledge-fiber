<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\StaticContent\Internal;

use Fledge\Async\Http\Server\StaticContent\DocumentRoot;

/**
 * Used in {@see DocumentRoot}.
 *
 * @internal
 */
final class ByteRangeRequest
{
    /**
     * @param non-empty-list<ByteRange> $ranges
     */
    public function __construct(
        public readonly string $boundary,
        public readonly array $ranges,
        public readonly string $contentType,
    ) {
    }
}
