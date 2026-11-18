<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\ReadableStreamIteratorAggregate;
use Psr\Http\Message\StreamInterface;

/**
 * Async ReadableStream over a PSR-7 request body.
 *
 * Backs large and unknown-size request bodies in FledgeHandler, mirroring
 * Guzzle's curl handler: a seekable body is rewound before the first read,
 * then read in chunks of curl's default 64 KiB upload buffer, and an empty
 * read ends the body like a 0 returned from CURLOPT_READFUNCTION. Only one
 * chunk is held in memory at a time.
 *
 * Closing this stream does not close the PSR-7 body: Guzzle owns it and
 * may rewind it again, for example to replay it on a 307 or 308 redirect.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class Psr7ReadableStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * curl's default CURLOPT_UPLOAD_BUFFERSIZE.
     */
    public const CHUNK_SIZE = 65536;

    private bool $started = false;

    private bool $closed = false;

    private readonly DeferredFuture $onClose;

    public function __construct(private readonly StreamInterface $body)
    {
        $this->onClose = new DeferredFuture;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        $cancellation?->throwIfRequested();

        if (! $this->started) {
            $this->started = true;

            if ($this->body->isSeekable()) {
                $this->body->rewind();
            }
        }

        $chunk = $this->body->eof() ? '' : $this->body->read(self::CHUNK_SIZE);

        if ($chunk === '') {
            $this->close();

            return null;
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;

        if (! $this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose)->ignore();
    }
}
