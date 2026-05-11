<?php

use Fledge\Async\Database\Mysql\Internal\MysqlEncodedValue;
use Fledge\Async\Database\Mysql\MysqlDataType;

enum MysqlEncodedValueTestStringEnum: string
{
    case Hello = 'hello';
}

enum MysqlEncodedValueTestIntEnum: int
{
    case Forty = 40;
}

it('encodes null as MysqlDataType::Null regardless of target', function () {
    expect(MysqlEncodedValue::fromValue(null)->getType())->toBe(MysqlDataType::Null);
    expect(MysqlEncodedValue::fromValue(null, MysqlDataType::LongBlob)->getType())->toBe(MysqlDataType::Null);
});

it('picks the right wire type for a string parameter based on the target column', function (?MysqlDataType $target, MysqlDataType $expected) {
    $encoded = MysqlEncodedValue::fromValue('payload', $target);
    expect($encoded->getType())->toBe($expected);
})->with([
    'no target (legacy default)' => [null, MysqlDataType::VarString],
    'varchar'                    => [MysqlDataType::Varchar, MysqlDataType::VarString],
    'string'                     => [MysqlDataType::String, MysqlDataType::VarString],
    'var_string'                 => [MysqlDataType::VarString, MysqlDataType::VarString],
    'tiny_blob'                  => [MysqlDataType::TinyBlob, MysqlDataType::LongBlob],
    'blob'                       => [MysqlDataType::Blob, MysqlDataType::LongBlob],
    'medium_blob'                => [MysqlDataType::MediumBlob, MysqlDataType::LongBlob],
    'long_blob'                  => [MysqlDataType::LongBlob, MysqlDataType::LongBlob],
    'json'                       => [MysqlDataType::Json, MysqlDataType::Json],
    'bit'                        => [MysqlDataType::Bit, MysqlDataType::VarString],
    'enum'                       => [MysqlDataType::Enum, MysqlDataType::VarString],
    'set'                        => [MysqlDataType::Set, MysqlDataType::VarString],
]);

it('encodes ints into the smallest wire type that fits', function (int $value, MysqlDataType $expected) {
    expect(MysqlEncodedValue::fromValue($value)->getType())->toBe($expected);
})->with([
    'tiny lower'   => [-128, MysqlDataType::Tiny],
    'tiny upper'   => [127, MysqlDataType::Tiny],
    'short lower'  => [-129, MysqlDataType::Short],
    'short upper'  => [32767, MysqlDataType::Short],
    'long lower'   => [-32769, MysqlDataType::Long],
    'long upper'   => [2147483647, MysqlDataType::Long],
    'long long'    => [2147483648, MysqlDataType::LongLong],
]);

it('encodes booleans as single-byte Tiny', function (bool $value, string $expectedByte) {
    $encoded = MysqlEncodedValue::fromValue($value);
    expect($encoded->getType())->toBe(MysqlDataType::Tiny);
    expect($encoded->getBytes())->toBe($expectedByte);
})->with([
    'true'  => [true, "\x01"],
    'false' => [false, "\0"],
]);

it('encodes floats as Double', function () {
    expect(MysqlEncodedValue::fromValue(3.14)->getType())->toBe(MysqlDataType::Double);
});

it('stringifies Stringable before applying string encoding', function () {
    $stringable = new class implements Stringable {
        public function __toString(): string
        {
            return 'cast me';
        }
    };

    $encoded = MysqlEncodedValue::fromValue($stringable, MysqlDataType::LongBlob);
    expect($encoded->getType())->toBe(MysqlDataType::LongBlob);
});

it('unwraps a string-backed enum to its value before encoding', function () {
    $encoded = MysqlEncodedValue::fromValue(MysqlEncodedValueTestStringEnum::Hello);
    expect($encoded->getType())->toBe(MysqlDataType::VarString);
});

it('unwraps an int-backed enum and routes through the int encoder', function () {
    $encoded = MysqlEncodedValue::fromValue(MysqlEncodedValueTestIntEnum::Forty);
    expect($encoded->getType())->toBe(MysqlDataType::Tiny);
});

it('throws TypeError for unsupported parameter types', function () {
    MysqlEncodedValue::fromValue(new stdClass);
})->throws(TypeError::class);
