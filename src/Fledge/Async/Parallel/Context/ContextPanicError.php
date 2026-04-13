<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context;

/**
 * @psalm-type FlattenedTrace = list<array<non-empty-string, scalar|list<scalar>>>
 */
final class ContextPanicError extends \Error
{
    use Internal\ContextException;
}
