<?php

use Fledge\Async\Redis\Protocol\ProtocolException;
use Fledge\Async\Redis\Protocol\RespParser;

/*
 * A malicious or MITM Redis must not be able to make the client allocate unbounded memory:
 * bulk length, aggregate count, nesting depth and unterminated line length are capped.
 */

function respParse(string ...$chunks): array
{
    $out = [];
    $parser = new RespParser(static function ($value) use (&$out): void {
        $out[] = $value->unwrap();
    });
    foreach ($chunks as $chunk) {
        $parser->push($chunk);
    }

    return $out;
}

it('still parses ordinary replies', function () {
    expect(respParse("*2\r\n\$3\r\nfoo\r\n:5\r\n"))->toBe([['foo', 5]]);
});

it('accepts nesting up to the depth cap', function () {
    $wire = str_repeat("*1\r\n", RespParser::MAX_DEPTH) . ":1\r\n";
    expect(respParse($wire))->toHaveCount(1);
});

it('rejects an oversize bulk length', function () {
    respParse('$' . (RespParser::MAX_BULK_LENGTH + 1) . "\r\n");
})->throws(ProtocolException::class, 'Bulk string length');

it('rejects an oversize aggregate count', function () {
    respParse('*' . (RespParser::MAX_AGGREGATE_COUNT + 1) . "\r\n");
})->throws(ProtocolException::class, 'Array length');

it('rejects excessive nesting', function () {
    respParse(str_repeat("*1\r\n", RespParser::MAX_DEPTH + 1) . ":1\r\n");
})->throws(ProtocolException::class, 'nesting');

it('rejects an unterminated line that grows past the cap', function () {
    $chunk = '+' . str_repeat('a', 65536);
    $chunks = array_fill(0, intdiv(RespParser::MAX_LINE_LENGTH, 65536) + 1, $chunk);
    respParse(...$chunks);
})->throws(ProtocolException::class, 'maximum length');
