<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Context\Internal;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Sync\Channel;

/**
 * @template-covariant TReceive
 * @template TSend
 * @implements Channel<TReceive, TSend>
 *
 * @internal
 */
final class ContextChannel implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly Channel $channel,
    ) {
    }

    public function send(mixed $data): void
    {
        $this->channel->send(new ContextMessage($data));
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        return $this->channel->receive($cancellation);
    }

    public function close(): void
    {
        $this->channel->close();
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->channel->onClose($onClose);
    }
}
