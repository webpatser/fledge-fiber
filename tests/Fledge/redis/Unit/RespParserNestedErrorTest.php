<?php

use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\Resp3ExtensionParser;
use Fledge\Async\Redis\Protocol\RespParser;

/*
 * An error nested inside an array reply (one failing command inside MULTI/EXEC, or
 * redis.error_reply in an EVAL table) must stay a value in its position. Throwing inside
 * the parser tears down the shared connection and fails every pending command.
 */

/**
 * @return list<RedisResponse>
 */
function respParseResponses(string $parserClass, string ...$chunks): array
{
    $out = [];
    $parser = new $parserClass(static function (RedisResponse $response) use (&$out): void {
        $out[] = $response;
    });
    foreach ($chunks as $chunk) {
        $parser->push($chunk);
    }

    return $out;
}

dataset('resp parsers', function () {
    yield 'pure PHP' => [RespParser::class];

    if (Resp3ExtensionParser::isUsable()) {
        yield 'resp3 extension' => [Resp3ExtensionParser::class];
    }
});

// EXEC reply for: SET k v, INCR k (fails on a non-integer), GET k
$execWire = "*3\r\n+OK\r\n-ERR value is not an integer or out of range\r\n\$1\r\nv\r\n";

it('keeps a nested EXEC error in its position', function (string $parserClass) use ($execWire) {
    $responses = respParseResponses($parserClass, $execWire);

    expect($responses)->toHaveCount(1);

    $result = $responses[0]->unwrap();

    expect($result)->toHaveCount(3)
        ->and($result[0])->toBe('OK')
        ->and($result[1])->toBeInstanceOf(QueryException::class)
        ->and($result[1]->getMessage())->toBe('ERR value is not an integer or out of range')
        ->and($result[2])->toBe('v');
})->with('resp parsers');

it('keeps parsing replies that follow an array with a nested error', function (string $parserClass) use ($execWire) {
    $responses = respParseResponses($parserClass, $execWire . ":42\r\n", "+PONG\r\n");

    expect($responses)->toHaveCount(3)
        ->and($responses[1]->unwrap())->toBe(42)
        ->and($responses[2]->unwrap())->toBe('PONG');
})->with('resp parsers');

it('keeps errors nested deeper than one level in position', function (string $parserClass) {
    $responses = respParseResponses($parserClass, "*2\r\n*2\r\n:1\r\n-ERR inner\r\n:7\r\n");

    $result = $responses[0]->unwrap();

    expect($result[0][0])->toBe(1)
        ->and($result[0][1])->toBeInstanceOf(QueryException::class)
        ->and($result[0][1]->getMessage())->toBe('ERR inner')
        ->and($result[1])->toBe(7);
})->with('resp parsers');

it('never returns a failed SELECT inside EXEC as a string', function (string $parserClass) {
    // EXEC reply for: SELECT 9999 (out of range), PING
    $responses = respParseResponses($parserClass, "*2\r\n-ERR DB index is out of range\r\n+PONG\r\n");

    $result = $responses[0]->unwrap();

    expect($result[0])->toBeInstanceOf(QueryException::class)
        ->and($result[0]->getMessage())->toBe('ERR DB index is out of range')
        ->and($result[1])->toBe('PONG');
})->with('resp parsers');

it('still throws on a top-level error reply', function (string $parserClass) {
    $responses = respParseResponses($parserClass, "-ERR unknown command\r\n");

    expect($responses[0])->toBeInstanceOf(RedisError::class);

    $responses[0]->unwrap();
})->with('resp parsers')->throws(QueryException::class, 'ERR unknown command');
