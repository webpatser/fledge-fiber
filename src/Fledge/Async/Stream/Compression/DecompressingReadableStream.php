<?php declare(strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */

namespace Fledge\Async\Stream\Compression;

use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\ReadableStreamIteratorAggregate;
use Fledge\Async\Stream\StreamException;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * Allows decompression of input streams using Zlib.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class DecompressingReadableStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    private ?\InflateContext $inflateContext;

    /**
     * @param ReadableStream $source Input stream to read compressed data from.
     * @param int $encoding Compression algorithm used, see `inflate_init()`.
     * @param array $options Algorithm options, see `inflate_init()`.
     *
     * @see http://php.net/manual/en/function.inflate-init.php
     */
    public function __construct(
        private readonly ReadableStream $source,
        private readonly int $encoding,
        private readonly array $options = [],
    ) {
        \set_error_handler(function ($errno, $message) {
            $this->close();

            throw new \Error("Failed initializing inflate context: $message");
        });

        try {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $this->inflateContext = \inflate_init($encoding, $options);
        } finally {
            \restore_error_handler();
        }
    }

    public function close(): void
    {
        $this->source->close();
        $this->inflateContext = null;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->inflateContext === null) {
            return null;
        }

        $data = $this->source->read($cancellation);

        // Needs a double guard, as stream might have been closed while reading
        /** @psalm-suppress TypeDoesNotContainNull */
        if ($this->inflateContext === null) {
            return null;
        }

        if ($data === null) {
            /** @psalm-suppress InvalidArgument */
            $decompressed = @\inflate_add($this->inflateContext, "", \ZLIB_FINISH);

            if ($decompressed === false) {
                $this->close();

                throw new StreamException("Failed adding data to inflate context");
            }

            $this->close();

            return $decompressed;
        }

        /** @psalm-suppress InvalidArgument */
        $decompressed = @\inflate_add($this->inflateContext, $data, \ZLIB_SYNC_FLUSH);

        if ($decompressed === false) {
            $this->close();

            throw new StreamException("Failed adding data to inflate context");
        }

        return $decompressed;
    }

    public function isReadable(): bool
    {
        return $this->inflateContext !== null && $this->source->isReadable();
    }

    /**
     * Gets the used compression encoding.
     *
     * @return int Encoding specified on construction time.
     */
    public function getEncoding(): int
    {
        return $this->encoding;
    }

    /**
     * Gets the used compression options.
     *
     * @return array Options array passed on construction time.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function isClosed(): bool
    {
        return $this->inflateContext === null || $this->source->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->source->onClose($onClose);
    }
}
