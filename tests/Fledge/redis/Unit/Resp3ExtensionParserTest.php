<?php

use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\Protocol\Resp3ExtensionParser;
use Fledge\Async\Redis\Protocol\RespParser;

beforeEach(function () {
    if (!extension_loaded('resp3')) {
        $this->markTestSkipped('resp3 PECL extension not loaded; install via `pie install webpatser/php-resp3`.');
    }
});

it('Resp3ExtensionParser implements ParserInterface', function () {
    $reflection = new ReflectionClass(Resp3ExtensionParser::class);

    expect($reflection->implementsInterface(ParserInterface::class))->toBeTrue();
});

it('emits a RedisValue for a simple string', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push("+OK\r\n");

    expect($emitted)->toHaveCount(1);
    expect($emitted[0])->toBeInstanceOf(RedisValue::class);
    expect($emitted[0]->unwrap())->toBe('OK');
});

it('emits a RedisValue for an integer', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push(":42\r\n");

    expect($emitted[0]->unwrap())->toBe(42);
});

it('emits a RedisValue for a bulk string', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push("$5\r\nhello\r\n");

    expect($emitted[0]->unwrap())->toBe('hello');
});

it('preserves binary-safe bulks', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push("$5\r\n\x00\x01\x02\x03\x04\r\n");

    expect($emitted[0]->unwrap())->toBe("\x00\x01\x02\x03\x04");
});

it('emits a RedisValue with null for null bulk', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push("$-1\r\n");

    expect($emitted[0])->toBeInstanceOf(RedisValue::class);
    expect($emitted[0]->unwrap())->toBeNull();
});

it('emits a RedisValue with array payload for arrays', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push("*2\r\n+OK\r\n:42\r\n");

    expect($emitted[0]->unwrap())->toBe(['OK', 42]);
});

it('emits a RedisError for error replies', function () {
    $emitted = [];
    $parser = new Resp3ExtensionParser(static function ($value) use (&$emitted): void {
        $emitted[] = $value;
    });

    $parser->push("-WRONGTYPE Operation against a key holding the wrong kind of value\r\n");

    expect($emitted[0])->toBeInstanceOf(RedisError::class);
    expect($emitted[0]->getMessage())->toBe('WRONGTYPE Operation against a key holding the wrong kind of value');
});

it('produces output structurally identical to RespParser on shared RESP2 fixtures', function () {
    $fixtures = [
        "+OK\r\n",
        ":42\r\n",
        "$5\r\nhello\r\n",
        "$-1\r\n",
        "*3\r\n$5\r\nhello\r\n:1\r\n+world\r\n",
        "-WRONGTYPE bad\r\n",
    ];

    foreach ($fixtures as $bytes) {
        $cExtensionEmitted = [];
        $purePhpEmitted = [];

        (new Resp3ExtensionParser(static fn ($value) => $cExtensionEmitted[] = $value))->push($bytes);
        (new RespParser(static fn ($value) => $purePhpEmitted[] = $value))->push($bytes);

        expect(count($cExtensionEmitted))->toBe(count($purePhpEmitted), "fixture: " . bin2hex($bytes));

        for ($i = 0; $i < count($cExtensionEmitted); $i++) {
            expect($cExtensionEmitted[$i]::class)->toBe($purePhpEmitted[$i]::class);
            if ($cExtensionEmitted[$i] instanceof RedisValue) {
                expect($cExtensionEmitted[$i]->unwrap())->toBe($purePhpEmitted[$i]->unwrap());
            } elseif ($cExtensionEmitted[$i] instanceof RedisError) {
                expect($cExtensionEmitted[$i]->getMessage())->toBe($purePhpEmitted[$i]->getMessage());
            }
        }
    }
});
