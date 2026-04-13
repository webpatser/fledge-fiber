<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\FormParser;

use Fledge\Async\Stream\BufferException;
use Fledge\Async\Stream\Payload;
use Fledge\Async\Stream\ReadableBuffer;
use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\ReadableStreamIteratorAggregate;
use Fledge\Async\Stream\StreamException;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Http1\Rfc7230;
use Fledge\Async\Http\HttpMessage;

/**
 * @psalm-import-type HeaderPairsType from HttpMessage
 * @psalm-import-type HeaderMapType from HttpMessage
 *
 * @implements \IteratorAggregate<int, string>
 */
final class StreamedField implements ReadableStream, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;
    use ReadableStreamIteratorAggregate;

    private readonly HttpMessage $message;

    private readonly Payload $payload;

    /**
     * @param HeaderPairsType $headerPairs Headers produced by {@see Rfc7230::parseHeaderPairs()}.
     */
    public function __construct(
        private readonly string $name,
        ?ReadableStream $stream = null,
        private readonly string $mimeType = "text/plain",
        private readonly ?string $filename = null,
        array $headerPairs = [],
    ) {
        $this->payload = new Payload($stream ?? new ReadableBuffer());
        $this->message = new Internal\FieldMessage($headerPairs);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function isFile(): bool
    {
        return $this->filename !== null;
    }

    /**
     * @return HeaderMapType
     *
     * @see HttpMessage::getHeaders()
     */
    public function getHeaders(): array
    {
        return $this->message->getHeaders();
    }

    /**
     * @return HeaderPairsType
     *
     * @see HttpMessage::getHeaderPairs()
     */
    public function getHeaderPairs(): array
    {
        return $this->message->getHeaderPairs();
    }

    /**
     * @see HttpMessage::getHeader()
     */
    public function getHeader(string $name): ?string
    {
        return $this->message->getHeader($name);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->payload->read($cancellation);
    }

    public function isReadable(): bool
    {
        return $this->payload->isReadable();
    }

    public function isClosed(): bool
    {
        return $this->payload->isClosed();
    }

    public function close(): void
    {
        $this->payload->close();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->payload->onClose($onClose);
    }

    /**
     * @see Payload::buffer()
     *
     * @throws BufferException|StreamException
     */
    public function buffer(?Cancellation $cancellation = null, int $limit = \PHP_INT_MAX): string
    {
        return $this->payload->buffer($cancellation, $limit);
    }
}
