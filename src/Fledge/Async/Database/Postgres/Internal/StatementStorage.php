<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Future;

/**
 * @template T
 *
 * @internal
 */
final class StatementStorage
{
    use ForbidCloning;
    use ForbidSerialization;

    public int $refCount = 1;

    /**
     * @param Future<T>|Future<void> $future
     */
    public function __construct(
        public readonly string $sql,
        public Future $future,
    ) {
    }
}
