<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

class Parser
{
    /** @var \Generator<array-key, int|string|null, string, void>|null */
    private ?\Generator $generator;

    /** @var list<string> */
    private array $buffers = [];

    private int $bufferLength = 0;

    /** @var int|string|null */
    private $delimiter;

    /** Read position inside $buffers[0]; always 0 between push() calls. */
    private int $offset = 0;

    /** Buffered bytes already searched for the current string delimiter without a match. */
    private int $scannedLength = 0;

    /** Diagnostic counter: bytes copied into new strings while buffering and slicing. Not part of the API. */
    private int $copiedBytes = 0;

    /** Diagnostic counter: bytes examined while searching for string delimiters. Not part of the API. */
    private int $scannedBytes = 0;

    /**
     * @param \Generator<array-key, int|string|null, string, void> $generator
     * @param int|null $maxDelimitedLength Maximum number of buffered bytes while waiting for a string delimiter
     *     (e.g. a line terminator). Null means unlimited.
     * @param \Closure(int):\Throwable|null $onOverflow Builds the exception thrown when the cap is exceeded.
     *
     * @throws InvalidDelimiterError If the generator yields an invalid delimiter.
     * @throws \Throwable If the generator throws.
     */
    public function __construct(
        \Generator $generator,
        private readonly ?int $maxDelimitedLength = null,
        private readonly ?\Closure $onOverflow = null,
    ) {
        $this->generator = $generator;
        $this->delimiter = $this->filterDelimiter($this->generator->current());

        if (!$this->generator->valid()) {
            $this->generator = null;
        }
    }

    /**
     * Cancels the generator parser and returns any remaining data in the internal buffer. Writing data after calling
     * this method will result in an error.
     */
    final public function cancel(): string
    {
        $this->compactHead();

        $buffer = \implode($this->buffers);

        $this->buffers = [];
        $this->generator = null;

        return $buffer;
    }

    /**
     * @return bool True if the parser can still receive more data to parse, false if it has ended and calling push
     *     will throw an exception.
     */
    final public function isValid(): bool
    {
        return $this->generator !== null;
    }

    /**
     * Adds data to the internal buffer and tries to continue parsing.
     *
     * @param string $data Data to append to the internal buffer.
     *
     * @throws InvalidDelimiterError If the generator yields an invalid delimiter.
     * @throws \Error If parsing has already been cancelled.
     * @throws \Throwable If the generator throws.
     */
    final public function push(string $data): void
    {
        if ($this->generator === null) {
            throw new \Error("The parser is no longer writable");
        }

        $length = \strlen($data);
        if ($length === 0) {
            return;
        }

        $this->buffers[] = $data;
        $this->bufferLength += $length;

        try {
            do {
                if (\is_int($this->delimiter)) {
                    if ($this->bufferLength < $this->delimiter) {
                        return;
                    }

                    $cutAt = $retainFrom = $this->delimiter;
                } elseif (\is_string($this->delimiter)) {
                    if (($cutAt = $this->findDelimiter($this->delimiter)) === null) {
                        if ($this->maxDelimitedLength !== null && $this->bufferLength > $this->maxDelimitedLength) {
                            throw $this->onOverflow !== null
                                ? ($this->onOverflow)($this->maxDelimitedLength)
                                : new \OverflowException(
                                    'Delimiter not found within ' . $this->maxDelimitedLength . ' bytes'
                                );
                        }

                        return;
                    }

                    $retainFrom = $cutAt + \strlen($this->delimiter);
                } else {
                    $cutAt = $retainFrom = $this->bufferLength;
                }

                $send = $this->take($cutAt, $retainFrom);

                $this->bufferLength -= $retainFrom;

                $this->delimiter = $this->filterDelimiter($this->generator->send($send));

                if (!$this->generator->valid()) {
                    $this->generator = null;
                    return;
                }
            } while ($this->bufferLength);
        } catch (\Throwable $exception) {
            $this->generator = null;
            throw $exception;
        } finally {
            // Slice the unconsumed remainder once per push instead of once per extracted frame.
            $this->compactHead();
        }
    }

    /**
     * Returns the next $cutAt buffered bytes and drops the buffer up to $retainFrom.
     *
     * Every frame completes inside the newest chunk (earlier chunks could not complete it), so after the first
     * take() of a push the buffer is a single string read through $offset, and each later frame is one substr().
     */
    private function take(int $cutAt, int $retainFrom): string
    {
        $this->scannedLength = 0;

        if (\count($this->buffers) > 1) {
            $this->compactHead();
            $this->buffers = [\implode($this->buffers)];
            $this->copiedBytes += $this->bufferLength;
        }

        $head = $this->buffers[0];
        $send = \substr($head, $this->offset, $cutAt);
        $this->copiedBytes += $cutAt;
        $this->offset += $retainFrom;

        if ($this->offset >= \strlen($head)) {
            $this->buffers = [];
            $this->offset = 0;
        }

        return $send;
    }

    /**
     * Finds the first occurrence of $delimiter in the buffer, without searching bytes a previous push already
     * searched for the same delimiter.
     *
     * @return int|null Position relative to the first unconsumed byte, or null if not found.
     */
    private function findDelimiter(string $delimiter): ?int
    {
        $delimiterLength = \strlen($delimiter);
        $from = \max(0, $this->scannedLength - $delimiterLength + 1);
        $last = \count($this->buffers) - 1;
        $piece = $this->buffers[$last];
        $pieceStart = $this->bufferLength - \strlen($piece);

        if ($last > 0 && $this->scannedLength !== $pieceStart) {
            // Defensive: push() leaves every earlier chunk searched, so this only runs if that ever changes.
            $this->compactHead();
            $piece = $this->buffers[0] = \implode($this->buffers);
            $this->buffers = [$piece];
            $this->copiedBytes += $this->bufferLength;
            $last = 0;
        }

        if ($last === 0) {
            $start = $this->offset + $from;
            $position = \strpos($piece, $delimiter, $start);
            $this->scannedBytes += $position === false
                ? \strlen($piece) - $start
                : $position + $delimiterLength - $start;

            if ($position === false) {
                $this->scannedLength = $this->bufferLength;
                return null;
            }

            return $position - $this->offset;
        }

        // Earlier chunks hold no match; one can only start in their last bytes and run into the new chunk.
        if ($from < $pieceStart) {
            $window = $this->tail($last, $pieceStart - $from) . \substr($piece, 0, $delimiterLength - 1);
            $this->copiedBytes += \strlen($window);
            $this->scannedBytes += \strlen($window);

            if (($position = \strpos($window, $delimiter)) !== false) {
                return $from + $position;
            }
        }

        $position = \strpos($piece, $delimiter);
        $this->scannedBytes += $position === false ? \strlen($piece) : $position + $delimiterLength;

        if ($position === false) {
            $this->scannedLength = $this->bufferLength;
            return null;
        }

        return $pieceStart + $position;
    }

    /**
     * Returns the last $length bytes of the buffered chunks before $buffers[$before].
     */
    private function tail(int $before, int $length): string
    {
        $tail = '';

        for ($i = $before - 1; $length > 0; $i--) {
            $piece = $this->buffers[$i];
            $available = \strlen($piece) - ($i === 0 ? $this->offset : 0);

            if ($available >= $length) {
                return \substr($piece, -$length) . $tail;
            }

            $tail = \substr($piece, \strlen($piece) - $available) . $tail;
            $length -= $available;
        }

        return $tail;
    }

    /**
     * Drops the consumed prefix of $buffers[0].
     */
    private function compactHead(): void
    {
        if ($this->offset > 0) {
            $this->buffers[0] = \substr($this->buffers[0], $this->offset);
            $this->copiedBytes += \strlen($this->buffers[0]);
            $this->offset = 0;
        }
    }

    /**
     * @param mixed $delimiter Value yielded from Generator.
     * @return int|string|null
     */
    private function filterDelimiter($delimiter)
    {
        \assert($this->generator instanceof \Generator, "Invalid parser state");

        if ($delimiter !== null
            && (!\is_int($delimiter) || $delimiter <= 0)
            && (!\is_string($delimiter) || !\strlen($delimiter))
        ) {
            throw new InvalidDelimiterError(
                $this->generator,
                \sprintf(
                    "Invalid value yielded: Expected NULL, an int greater than 0, or a non-empty string; %s given",
                    \is_object($delimiter) ? \sprintf("instance of %s", \get_class($delimiter)) : \gettype($delimiter),
                )
            );
        }

        return $delimiter;
    }
}
