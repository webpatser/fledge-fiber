<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Stream\Payload;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 stream over an async response Payload.
 *
 * Backs Guzzle's stream=true request option: the response resolves at
 * headers and each read() suspends the current fiber until the next chunk
 * arrives instead of buffering the whole body up front. The stream is
 * forward-only (non-seekable) because the bytes come straight off the
 * socket.
 */
class AsyncBodyStream implements StreamInterface
{
    protected string $buffer = '';

    protected int $offset = 0;

    protected bool $closed = false;

    public function __construct(
        protected Payload $payload,
        protected ?int $size = null,
    ) {}

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->payload->close();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    /**
     * The size is only known when the response carried a Content-Length.
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && ! $this->payload->isReadable();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('AsyncBodyStream is not seekable');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('AsyncBodyStream is not writable');
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new \RuntimeException('AsyncBodyStream is closed');
        }

        if ($length <= 0) {
            return '';
        }

        // The payload hands out chunks of arbitrary size; keep whatever
        // exceeds the requested length for the next read.
        if ($this->buffer === '') {
            $chunk = $this->payload->read();

            if ($chunk === null) {
                return '';
            }

            $this->buffer = $chunk;
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($data));
        $this->offset += strlen($data);

        return $data;
    }

    public function getContents(): string
    {
        if ($this->closed) {
            throw new \RuntimeException('AsyncBodyStream is closed');
        }

        // Payload::buffer() refuses to run after read(), so drain via read().
        $contents = $this->buffer;
        $this->buffer = '';

        while (($chunk = $this->payload->read()) !== null) {
            $contents .= $chunk;
        }

        $this->offset += strlen($contents);

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = ['seekable' => false, 'eof' => $this->eof()];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
