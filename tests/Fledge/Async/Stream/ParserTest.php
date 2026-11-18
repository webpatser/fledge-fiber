<?php

declare(strict_types=1);

use Fledge\Async\Redis\Protocol\RespParser;
use Fledge\Async\Stream\Parser;

/*
 * Parser used to implode every buffered chunk on each push, scan for a string delimiter from offset 0 each
 * time, and recopy the whole remaining tail after every extracted frame. A large frame delivered in small
 * chunks therefore cost work quadratic in its size, and a chunk carrying many small frames cost work
 * quadratic in the chunk. The work is proven through the private diagnostic counters, never through timing.
 *
 * Equivalence is proven against QuadraticReferenceParser, a verbatim copy of the previous push() loop: for every
 * input, split at every byte position (and every pair of positions for short inputs, and byte by byte), both
 * parsers must yield the same frames, raise the same error, and keep the same remainder.
 */
final class QuadraticReferenceParser
{
    private ?\Generator $generator;

    private array $buffers = [];

    private int $bufferLength = 0;

    private $delimiter;

    public function __construct(
        \Generator $generator,
        private readonly ?int $maxDelimitedLength = null,
        private readonly ?\Closure $onOverflow = null,
    ) {
        $this->generator = $generator;
        $this->delimiter = $this->generator->current();

        if (!$this->generator->valid()) {
            $this->generator = null;
        }
    }

    public function cancel(): string
    {
        $buffer = \implode($this->buffers);

        $this->buffers = [];
        $this->generator = null;

        return $buffer;
    }

    public function isValid(): bool
    {
        return $this->generator !== null;
    }

    public function push(string $data): void
    {
        if ($this->generator === null) {
            throw new \Error("The parser is no longer writable");
        }

        $length = \strlen($data);
        if ($length === 0) {
            return;
        }

        $this->bufferLength += $length;

        try {
            do {
                if (\is_int($this->delimiter) && $this->bufferLength < $this->delimiter) {
                    return;
                }

                if (!empty($this->buffers)) {
                    $this->buffers[] = $data;
                    $data = \implode($this->buffers);
                    $this->buffers = [];
                }

                if (\is_int($this->delimiter)) {
                    $cutAt = $retainFrom = $this->delimiter;
                } elseif (\is_string($this->delimiter)) {
                    if (($cutAt = \strpos($data, $this->delimiter)) === false) {
                        if ($this->maxDelimitedLength !== null && \strlen($data) > $this->maxDelimitedLength) {
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

                if ($this->bufferLength > $cutAt) {
                    $send = \substr($data, 0, $cutAt);
                    $data = \substr($data, $retainFrom);
                } else {
                    $send = $data;
                    $data = '';
                }

                $this->bufferLength -= $retainFrom;

                $this->delimiter = $this->generator->send($send);

                if (!$this->generator->valid()) {
                    $this->generator = null;
                    return;
                }
            } while ($this->bufferLength);
        } catch (\Throwable $exception) {
            $this->generator = null;
            throw $exception;
        } finally {
            if (\strlen($data)) {
                $this->buffers[] = $data;
            }
        }
    }
}

/**
 * @return array{0: int, 1: int} [bytes copied, bytes scanned]
 */
function parserWorkCounters(Parser $parser): array
{
    return (fn (): array => [$this->copiedBytes, $this->scannedBytes])->call($parser);
}

/**
 * @param class-string $class
 * @param list<string> $chunks
 */
function runParserOver(string $class, \Closure $factory, array $chunks, ?int $max): array
{
    $frames = new \ArrayObject();
    $parser = new $class($factory($frames), $max);
    $error = null;

    foreach ($chunks as $chunk) {
        try {
            $parser->push($chunk);
        } catch (\Throwable $exception) {
            $error = \get_class($exception) . ': ' . $exception->getMessage();
            break;
        }
    }

    $valid = $parser->isValid();

    return ['frames' => $frames->getArrayCopy(), 'error' => $error, 'valid' => $valid, 'rest' => $parser->cancel()];
}

/**
 * @return iterable<string, list<string>>
 */
function everySplitOf(string $input): iterable
{
    $length = \strlen($input);

    yield 'whole' => [$input];
    yield 'bytes' => \str_split($input);

    for ($i = 1; $i < $length; $i++) {
        yield "at $i" => [\substr($input, 0, $i), \substr($input, $i)];
    }

    if ($length <= 48) {
        for ($i = 1; $i < $length; $i++) {
            for ($j = $i + 1; $j < $length; $j++) {
                yield "at $i,$j" => [\substr($input, 0, $i), \substr($input, $i, $j - $i), \substr($input, $j)];
            }
        }
    }
}

dataset('parser scripts', function (): array {
    $repeat = static fn (int|string|null $delimiter): \Closure => static function (\ArrayObject $frames) use ($delimiter): \Generator {
        while (true) {
            $frames[] = yield $delimiter;
        }
    };

    $resp = static function (\ArrayObject $frames): \Generator {
        while (true) {
            $type = yield 1;
            $payload = yield "\r\n";
            $frames[] = $type . $payload;
            if ($type === '$') {
                $frames[] = yield (int) $payload;
                $frames[] = yield 2;
            }
        }
    };

    $endsEarly = static function (\ArrayObject $frames): \Generator {
        for ($i = 0; $i < 3; $i++) {
            $frames[] = yield "\n";
        }
    };

    $throws = static function (\ArrayObject $frames): \Generator {
        while (true) {
            $frame = yield "\n";
            if ($frame === 'boom') {
                throw new \RuntimeException('generator failed on boom');
            }
            $frames[] = $frame;
        }
    };

    $switching = static function (\ArrayObject $frames): \Generator {
        while (true) {
            $frames[] = yield "\n";
            $frames[] = yield '--';
            $frames[] = yield 2;
            $frames[] = yield null;
            $frames[] = yield 'xyx';
        }
    };

    return [
        'crlf lines with an empty frame' => [$repeat("\r\n"), "a\r\nbb\r\n\r\nccc\r\nrest", null],
        'four-byte delimiter with near misses' => [$repeat("\r\n\r\n"), "A: 1\r\nB: 2\r\n\r\nbody\r\n\r\r\n\r\n\r\n\r\nX\r\n\r", null],
        'self-overlapping delimiter' => [$repeat('aab'), 'aaaabxaabaaabaaaaab', null],
        'fixed lengths' => [$repeat(3), 'abcdefghijklmnopqrstu', null],
        'null delimiter' => [$repeat(null), 'abcdefgh', null],
        'resp mix' => [$resp, "+OK\r\n\$3\r\nfoo\r\n:42\r\n\$5\r\nab\r\nc\r\n-ERR x\r\n", null],
        'generator ends early' => [$endsEarly, "a\nb\nc\nd\ne", null],
        'generator throws' => [$throws, "a\nbb\nboom\nafter\nx", null],
        'overflow' => [$repeat("\r\n"), "ab\r\nabcdef\r\nxy\r\nlonger-tail", 5],
        'switching delimiters' => [$switching, "one\ntwo--34rest-xyxyx\nz--zz", null],
    ];
});

it('yields exactly the frames of the previous implementation for every split', function (\Closure $factory, string $input, ?int $max): void {
    foreach (everySplitOf($input) as $name => $chunks) {
        $expected = runParserOver(QuadraticReferenceParser::class, $factory, $chunks, $max);
        $actual = runParserOver(Parser::class, $factory, $chunks, $max);

        expect($actual)->toBe($expected, "split $name");
    }
})->with('parser scripts');

it('yields the expected frames for a known input', function (): void {
    $result = runParserOver(Parser::class, static function (\ArrayObject $frames): \Generator {
        while (true) {
            $frames[] = yield "\r\n";
        }
    }, ["a\r", "\nbb\r\n\r", "\nccc\r\nre", 'st'], null);

    expect($result)->toBe(['frames' => ['a', 'bb', '', 'ccc'], 'error' => null, 'valid' => true, 'rest' => 'rest']);
});

it('does linear work for a large line delivered in small chunks', function (): void {
    $size = 256 * 1024;
    $frames = new \ArrayObject();
    $parser = new Parser((static function () use ($frames): \Generator {
        while (true) {
            $frames[] = yield "\r\n";
        }
    })());

    foreach (\str_split(\str_repeat('a', $size) . "\r\n", 256) as $chunk) {
        $parser->push($chunk);
    }

    [$copied, $scanned] = parserWorkCounters($parser);

    expect($frames->count())->toBe(1)
        ->and(\strlen($frames[0]))->toBe($size)
        ->and($copied)->toBeLessThanOrEqual(3 * $size)
        ->and($scanned)->toBeLessThanOrEqual(2 * $size);
});

it('does linear work for a multi-byte delimiter delivered byte by byte', function (): void {
    $head = \str_repeat("Header: value\r\n", 300);
    $frames = new \ArrayObject();
    $parser = new Parser((static function () use ($frames): \Generator {
        while (true) {
            $frames[] = yield "\r\n\r\n";
        }
    })());

    $bytes = \str_split($head . "\r\n", 1);
    foreach ($bytes as $byte) {
        $parser->push($byte);
    }

    [$copied, $scanned] = parserWorkCounters($parser);
    $size = \strlen($head);
    // Each push may examine a window of at most 2 * (4 - 1) bytes around the chunk boundary.
    $perPush = 2 * 3 * \count($bytes);

    expect($frames->getArrayCopy())->toBe([\substr($head, 0, -2)])
        ->and($copied)->toBeLessThanOrEqual(2 * $size + $perPush)
        ->and($scanned)->toBeLessThanOrEqual($size + $perPush);
});

it('does linear work for one chunk carrying many small frames', function (): void {
    $chunk = \str_repeat("+OK\r\n", 1638);
    $frames = new \ArrayObject();
    $parser = new Parser((static function () use ($frames): \Generator {
        while (true) {
            $frames[] = yield "\r\n";
        }
    })());

    $parser->push($chunk);

    [$copied, $scanned] = parserWorkCounters($parser);

    expect($frames->count())->toBe(1638)
        ->and($copied)->toBeLessThanOrEqual(2 * \strlen($chunk))
        ->and($scanned)->toBeLessThanOrEqual(2 * \strlen($chunk));
});

it('does linear work for a large RESP array reply read in 8 KiB chunks', function (): void {
    $count = 4000;
    $reply = '*' . $count . "\r\n" . \str_repeat("\$5\r\nhello\r\n", $count);
    $responses = [];
    $resp = new RespParser(static function ($response) use (&$responses): void {
        $responses[] = $response;
    });

    foreach (\str_split($reply, 8192) as $chunk) {
        $resp->push($chunk);
    }

    $parser = (fn (): Parser => $this->parser)->call($resp);
    [$copied, $scanned] = parserWorkCounters($parser);

    expect($responses)->toHaveCount(1)
        ->and($responses[0]->unwrap())->toHaveCount($count)
        ->and($copied)->toBeLessThanOrEqual(3 * \strlen($reply))
        ->and($scanned)->toBeLessThanOrEqual(2 * \strlen($reply));
});
