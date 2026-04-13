<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Internal;

use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\ReadableStreamIteratorAggregate;
use Fledge\Async\Stream\StreamException;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * @internal
 * @implements \IteratorAggregate<int, string>
 */
final class SizeLimitingReadableStream implements ReadableStream, \IteratorAggregate
{
    use ForbidSerialization;
    use ForbidCloning;
    use ReadableStreamIteratorAggregate;

    private int $bytesRead = 0;

    private ?\Throwable $exception = null;

    public function __construct(
        private readonly ReadableStream $source,
        private readonly int $sizeLimit
    ) {
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->exception) {
            throw $this->exception;
        }

        $chunk = $this->source->read($cancellation);

        if ($chunk !== null) {
            $this->bytesRead += \strlen($chunk);
            if ($this->bytesRead > $this->sizeLimit) {
                $this->source->close();

                throw $this->exception = new StreamException(
                    "Configured body size exceeded: {$this->bytesRead} bytes received, while the configured limit is {$this->sizeLimit} bytes",
                );
            }
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        return $this->source->isReadable();
    }

    public function close(): void
    {
        $this->source->close();
    }

    public function isClosed(): bool
    {
        return $this->source->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->source->onClose($onClose);
    }
}
