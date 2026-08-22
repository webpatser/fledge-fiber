<?php declare(strict_types=1);

namespace Fledge\Async\Internal;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * @internal
 */
final class WrappedCancellation implements Cancellation
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly Cancellation $cancellation
    ) {
    }

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        return $this->cancellation->subscribe($callback);
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
        $this->cancellation->unsubscribe($id);
    }

    #[\Override]
    public function isRequested(): bool
    {
        return $this->cancellation->isRequested();
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        $this->cancellation->throwIfRequested();
    }
}
