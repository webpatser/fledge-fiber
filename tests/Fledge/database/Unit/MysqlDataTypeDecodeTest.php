<?php

use Fledge\Async\Database\Mysql\MysqlDataType;

const MYSQL_DECODE_UNSIGNED = 0x20;
const MYSQL_DECODE_BINARY = 0x80;

it('decodes a binary-protocol UNSIGNED BIGINT as mysqlnd does: int up to PHP_INT_MAX, decimal string above', function (string $bytes, int|string $expected) {
    $offset = 0;

    expect(MysqlDataType::LongLong->decodeBinary($bytes, $offset, MYSQL_DECODE_UNSIGNED))->toBe($expected)
        ->and($offset)->toBe(8);
})->with([
    'zero' => [pack('P', 0), 0],
    'PHP_INT_MAX' => [pack('P', PHP_INT_MAX), PHP_INT_MAX],
    '2^63' => ["\x00\x00\x00\x00\x00\x00\x00\x80", '9223372036854775808'],
    'max unsigned' => [str_repeat("\xff", 8), '18446744073709551615'],
]);

it('keeps a signed binary-protocol BIGINT negative', function () {
    expect(MysqlDataType::LongLong->decodeBinary(str_repeat("\xff", 8)))->toBe(-1);
});

it('decodes a binary-protocol BIT value as a big-endian unsigned integer, as mysqlnd does', function (string $bits, int|string $expected) {
    $bytes = chr(strlen($bits)).$bits.'tail';
    $offset = 0;

    // MySQL flags BIT columns UNSIGNED | BINARY; the decoded value must not depend on that.
    expect(MysqlDataType::Bit->decodeBinary($bytes, $offset, MYSQL_DECODE_UNSIGNED | MYSQL_DECODE_BINARY))->toBe($expected)
        ->and($offset)->toBe(1 + strlen($bits))
        ->and(MysqlDataType::Bit->decodeBinary($bytes))->toBe($expected);
})->with([
    'BIT(1) b\'1\'' => ["\x01", 1],
    'BIT(1) b\'0\'' => ["\x00", 0],
    'BIT(8) 0x31 (ASCII "1")' => ["\x31", 49],
    'BIT(16) 0x0102' => ["\x01\x02", 258],
    'BIT(24) 0xabcdef' => ["\xab\xcd\xef", 0xabcdef],
    'BIT(64) PHP_INT_MAX' => ["\x7f".str_repeat("\xff", 7), PHP_INT_MAX],
    'BIT(64) 2^63' => ["\x80".str_repeat("\x00", 7), '9223372036854775808'],
    'BIT(64) all ones' => [str_repeat("\xff", 8), '18446744073709551615'],
]);
