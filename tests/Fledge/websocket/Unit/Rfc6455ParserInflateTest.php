<?php

declare(strict_types=1);

use Fledge\Async\WebSocket\Compression\Rfc7692Compression;
use Fledge\Async\WebSocket\Parser\Rfc6455Parser;
use Fledge\Async\WebSocket\Parser\WebsocketFrameHandler;
use Fledge\Async\WebSocket\Parser\WebsocketFrameType;
use Fledge\Async\WebSocket\Parser\WebsocketParserException;
use Fledge\Async\WebSocket\WebsocketCloseCode;

/*
 * permessage-deflate (RFC 7692): decompressed bytes count toward the message size limit, and a deflate bomb is
 * rejected with 1009 before its full output is allocated.
 */

function inflateTestFrame(string $payload, bool $final, bool $compressed, int $opcode): string
{
    $first = ($final ? 0x80 : 0) | ($compressed ? 0x40 : 0) | $opcode;
    $length = \strlen($payload);

    if ($length > 0xFFFF) {
        return \chr($first) . \chr(0x7F) . \pack('J', $length) . $payload;
    }

    if ($length > 125) {
        return \chr($first) . \chr(0x7E) . \pack('n', $length) . $payload;
    }

    return \chr($first) . \chr($length) . $payload;
}

function inflateTestDeflate(string $part, int $repeat = 1): string
{
    $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
    $out = '';

    // deflate_add() ignores a flush on empty input, so the last part carries the sync flush.
    for ($i = 1; $i <= $repeat; $i++) {
        $out .= \deflate_add($deflate, $part, $i === $repeat ? \ZLIB_SYNC_FLUSH : \ZLIB_NO_FLUSH);
    }

    return \substr($out, 0, -4); // Strip the 00 00 ff ff tail, as RFC 7692 senders do.
}

function inflateTestParser(int $messageSizeLimit): array
{
    $handler = new class implements WebsocketFrameHandler
    {
        public string $data = '';

        public int $frames = 0;

        public function handleFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): void
        {
            $this->data .= $data;
            $this->frames++;
        }
    };

    // Client side context and parser (masked: true), so server frames arrive unmasked.
    $context = Rfc7692Compression::fromServerHeader('permessage-deflate');

    $parser = new Rfc6455Parser(
        $handler,
        masked: true,
        compressionContext: $context,
        messageSizeLimit: $messageSizeLimit,
        frameSizeLimit: 2 ** 20,
    );

    return [$parser, $handler];
}

it('rejects a deflate bomb with 1009 without inflating it in full', function () {
    // 64 MB of zeros compresses to roughly 64 KB, well under the 1 MB frame and message limits on the wire.
    $bomb = inflateTestDeflate(\str_repeat("\0", 2 ** 20), 64);

    expect(\strlen($bomb))->toBeLessThan(2 ** 20);

    [$parser, $handler] = inflateTestParser(2 ** 20);
    $frame = inflateTestFrame($bomb, true, true, 0x2);
    unset($bomb);

    \gc_collect_cycles();
    $baseline = \memory_get_usage();
    \memory_reset_peak_usage();

    try {
        $parser->push($frame);
        $this->fail('Expected the deflate bomb to be rejected');
    } catch (WebsocketParserException $e) {
        expect($e->getCode())->toBe(WebsocketCloseCode::MESSAGE_TOO_LARGE);
    }

    expect(\memory_get_peak_usage() - $baseline)->toBeLessThan(8 * 2 ** 20)
        ->and($handler->frames)->toBe(0);
});

it('counts decompressed bytes across fragments of one message', function () {
    $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
    $first = \deflate_add($deflate, \str_repeat('a', 600 * 1024), \ZLIB_SYNC_FLUSH);
    $second = \substr(\deflate_add($deflate, \str_repeat('a', 600 * 1024), \ZLIB_SYNC_FLUSH), 0, -4);

    [$parser, $handler] = inflateTestParser(2 ** 20);

    $parser->push(inflateTestFrame($first, false, true, 0x2));

    expect(fn () => $parser->push(inflateTestFrame($second, true, false, 0x0)))
        ->toThrow(WebsocketParserException::class, 'Received payload exceeds maximum allowable size');
});

it('decompresses messages within the limit unchanged', function () {
    $text = \str_repeat('héllo wörld, ', 40_000); // ~560 KB of UTF-8 text, compresses to a few KB.

    [$parser, $handler] = inflateTestParser(2 ** 20);
    $parser->push(inflateTestFrame(inflateTestDeflate($text), true, true, 0x1));

    expect($handler->data)->toBe($text);
});

it('reassembles a fragmented compressed text message split inside a multibyte character', function () {
    $text = \str_repeat('ü', 5000);
    $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
    $first = \deflate_add($deflate, \substr($text, 0, 4999), \ZLIB_SYNC_FLUSH); // ends mid-character
    $second = \substr(\deflate_add($deflate, \substr($text, 4999), \ZLIB_SYNC_FLUSH), 0, -4);

    [$parser, $handler] = inflateTestParser(2 ** 20);
    $parser->push(inflateTestFrame($first, false, true, 0x1));
    $parser->push(inflateTestFrame($second, true, false, 0x0));

    expect($handler->data)->toBe($text);
});
