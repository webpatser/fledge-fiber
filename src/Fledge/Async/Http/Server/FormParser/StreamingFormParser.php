<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\FormParser;

use Fledge\Async\Stream\ReadableIterableStream;
use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Http1\Rfc7230;
use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\InvalidHeaderException;
use Fledge\Async\Http\Server\HttpErrorException;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\DisposedException;
use Fledge\Async\Pipeline;
use Fledge\Async\Queue;
use Revolt\EventLoop;

final class StreamingFormParser
{
    use ForbidCloning;
    use ForbidSerialization;

    /** Default cap on the header block of a single multipart part, in bytes. */
    public const DEFAULT_PART_HEADER_SIZE_LIMIT = 16384;

    /** @var int Prevent requests from creating arbitrary many fields causing lot of processing time */
    private readonly int $fieldCountLimit;

    /** @var int Prevent a multipart part from buffering an unbounded header block */
    private readonly int $partHeaderSizeLimit;

    public function __construct(?int $fieldCountLimit = null, ?int $partHeaderSizeLimit = null)
    {
        $this->fieldCountLimit = $fieldCountLimit ?? (int) \ini_get('max_input_vars') ?: 1000;
        $this->partHeaderSizeLimit = $partHeaderSizeLimit ?? self::DEFAULT_PART_HEADER_SIZE_LIMIT;
    }

    /**
     * @return \Traversable<int, StreamedField> Traversable throws {@see HttpErrorException} if parsing a field fails.
     */
    public function parseForm(Request $request, ?int $bodySizeLimit = null): \Traversable
    {
        $boundary = parseContentBoundary($request->getHeader('content-type') ?? '');
        if ($boundary === null) {
            return Pipeline::fromIterable([])->getIterator();
        }

        $source = new Queue();
        $pipeline = $source->pipe();

        $body = $request->getBody();
        if ($bodySizeLimit !== null) {
            $body->increaseSizeLimit($bodySizeLimit);
        }

        EventLoop::queue(function () use ($boundary, $source, $body): void {
            try {
                $boundary === ''
                    ? $this->incrementalFieldParse($source, $body)
                    : $this->incrementalBoundaryParse($source, $body, $boundary);

                $source->complete();
            } catch (\Throwable $e) {
                $source->error($e);
            }
        });

        return $pipeline->getIterator();
    }

    private function incrementalBoundaryParse(Queue $source, ReadableStream $body, string $boundary): void
    {
        $fieldCount = 0;
        $queue = null;

        try {
            $buffer = "";

            // RFC 7578, RFC 2046 Section 5.1.1
            $boundarySeparator = "--{$boundary}";
            while (\strlen($buffer) < \strlen($boundarySeparator) + 4) {
                $buffer .= $chunk = $body->read();

                if ($chunk === null) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Request body ended unexpectedly");
                }
            }

            $offset = \strlen($boundarySeparator);
            if (\strncmp($buffer, $boundarySeparator, $offset)) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Invalid boundary");
            }

            $boundarySeparator = "\r\n$boundarySeparator";
            $end = 0; // For Psalm

            while (\substr_compare($buffer, "--\r\n", $offset)) {
                $offset += 2;
                $searchPos = $offset;

                // The header block runs from $offset up to the blank line; it may be at most partHeaderSizeLimit bytes
                // however the reads fall. Unterminated, up to 3 trailing bytes may be the start of the blank line.
                while (($end = \strpos($buffer, "\r\n\r\n", $searchPos)) === false) {
                    if (\strlen($buffer) - $offset > $this->partHeaderSizeLimit + 3) {
                        throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Multipart part headers too large");
                    }

                    // Resume the search just before the new data so a split CRLFCRLF is still found.
                    $searchPos = \max($offset, \strlen($buffer) - 3);
                    $buffer .= $chunk = $body->read();

                    if ($chunk === null) {
                        throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Request body ended unexpectedly");
                    }
                }

                if ($end - $offset > $this->partHeaderSizeLimit) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Multipart part headers too large");
                }

                if ($fieldCount++ === $this->fieldCountLimit) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Maximum number of variables exceeded");
                }

                try {
                    $headers = Rfc7230::parseHeaderPairs(\substr($buffer, $offset, $end + 2 - $offset));
                } catch (InvalidHeaderException $e) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Invalid headers in body part", $e);
                }

                $headerMap = [];
                foreach ($headers as [$key, $value]) {
                    $headerMap[\strtolower($key)][] = $value;
                }

                $count = \preg_match(
                    '#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#',
                    $headerMap["content-disposition"][0] ?? "",
                    $matches
                );

                if (!$count || !isset($matches[1])) {
                    throw new HttpErrorException(
                        HttpStatus::BAD_REQUEST,
                        "Invalid content-disposition header within multipart form",
                    );
                }

                $fieldName = $matches[1];

                // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

                $queue = new Queue();
                $stream = new ReadableIterableStream($queue->iterate());
                $field = new StreamedField(
                    $fieldName,
                    $stream,
                    $headerMap["content-type"][0] ?? "text/plain",
                    $matches[2] ?? null,
                    $headers
                );

                $future = $source->pushAsync($field);

                $buffer = \substr($buffer, $end + 4);

                while (($end = \strpos($buffer, $boundarySeparator)) === false) {
                    if (\strlen($buffer) > \strlen($boundarySeparator)) {
                        $position = \strlen($buffer) - \strlen($boundarySeparator);
                        $queue->push(\substr($buffer, 0, $position));
                        $buffer = \substr($buffer, $position);
                    }

                    $buffer .= $chunk = $body->read();

                    if ($chunk === null) {
                        throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Request body ended unexpectedly");
                    }
                }

                $queue->push(\substr($buffer, 0, $end));
                $queue->complete();
                $queue = null;
                $offset = $end + \strlen($boundarySeparator);

                // Need the 4 bytes after the delimiter ("--\r\n" or "\r\n" plus the next headers) to decide.
                while (\strlen($buffer) < $offset + 4) {
                    $buffer .= $chunk = $body->read();

                    if ($chunk === null) {
                        throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Request body ended unexpectedly");
                    }
                }

                $future->await();
            }
        } catch (\Throwable $e) {
            $queue?->error($e);
            throw $e;
        }
    }

    private function incrementalFieldParse(Queue $source, ReadableStream $body): void
    {
        $fieldCount = 0;
        $queue = null;

        try {
            $buffer = "";
            $nextPos = 0; // For Psalm
            $scanPos = 0; // Bytes of $buffer already known to hold no "=" or "&".

            while (null !== $chunk = $body->read()) {
                if ($chunk === "") {
                    continue;
                }

                $buffer .= $chunk;

                parse_parameter:

                // A key ends at whichever of "=" or "&" comes first (as parse_str does); each byte is scanned once.
                $keyEnd = $scanPos + \strcspn($buffer, "=&", $scanPos);
                if ($keyEnd === \strlen($buffer)) {
                    $scanPos = $keyEnd;
                    continue;
                }

                $scanPos = 0;
                $fieldName = \urldecode(\substr($buffer, 0, $keyEnd));
                $hasValue = $buffer[$keyEnd] === "=";
                $buffer = \substr($buffer, $keyEnd + 1);

                if ($fieldName === "") {
                    // parse_str drops an empty key and its value; skip up to the next "&".
                    while ($hasValue && false === ($nextPos = \strpos($buffer, "&"))) {
                        $buffer = "";
                        $chunk = $body->read();

                        if ($chunk === null) {
                            return;
                        }

                        $buffer = $chunk;
                    }

                    if ($hasValue) {
                        $buffer = \substr($buffer, $nextPos + 1);
                    }

                    goto parse_parameter;
                }

                if ($hasValue) {
                    $queue = new Queue();

                    if ($fieldCount++ === $this->fieldCountLimit) {
                        throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Maximum number of variables exceeded");
                    }

                    $future = $source->pushAsync(new StreamedField(
                        $fieldName,
                        new ReadableIterableStream($queue->iterate())
                    ));

                    while (false === ($nextPos = \strpos($buffer, "&"))) {
                        // The buffer holds no "&", so all of it belongs to this value. Flush it except an escape
                        // that may be cut off at the end ("%" or "%X"); the buffer then never grows past two bytes
                        // plus one read, and the "&" search above only covers that.
                        $length = \strlen($buffer);
                        $keep = 0;
                        if ($length > 0 && $buffer[$length - 1] === "%") {
                            $keep = 1;
                        } elseif ($length > 1 && $buffer[$length - 2] === "%") {
                            $keep = 2;
                        }

                        if ($length > $keep) {
                            $chunk = \substr($buffer, 0, $length - $keep);
                            $buffer = \substr($buffer, $length - $keep);

                            try {
                                $queue->push(\urldecode($chunk));
                            } catch (DisposedException) {
                                // Ignore and continue consuming this field.
                            }
                        }

                        $chunk = $body->read();

                        if ($chunk === null) {
                            try {
                                $queue->push(\urldecode($buffer));
                            } catch (DisposedException) {
                                // Ignore, we've now completed anyway.
                            }
                            $queue->complete();
                            $queue = null;

                            return;
                        }

                        $buffer .= $chunk;
                    }

                    try {
                        $queue->push(\urldecode(\substr($buffer, 0, $nextPos)));
                    } catch (DisposedException) {
                        // Ignore, we need to keep consuming data until the next field.
                    }
                    $queue->complete();
                    $queue = null;

                    $buffer = \substr($buffer, $nextPos + 1);

                    $future->await();
                    goto parse_parameter;
                }

                if ($fieldCount++ === $this->fieldCountLimit) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Maximum number of variables exceeded");
                }

                $source->push(new StreamedField($fieldName));

                goto parse_parameter;
            }

            // A trailing key without "=" or "&"; compare to "" so a key named "0" is kept.
            if ($buffer !== "" && ($fieldName = \urldecode($buffer)) !== "") {
                if ($fieldCount === $this->fieldCountLimit) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, "Maximum number of variables exceeded");
                }

                $source->push(new StreamedField($fieldName));
            }
        } catch (\Throwable $e) {
            $queue?->error($e);
            throw $e;
        }
    }
}
