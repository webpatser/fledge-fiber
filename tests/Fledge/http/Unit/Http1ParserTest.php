<?php

use Fledge\Async\Future;
use Fledge\Async\Http\Client\Connection\HttpStream;
use Fledge\Async\Http\Client\Connection\Internal\Http1Parser;
use Fledge\Async\Http\Client\ParseException;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\NullCancellation;
use Fledge\Async\Stream\InternetAddress;
use function Fledge\Async\Http\Client\events;

/**
 * A parser for a chunked GET response whose decoded body lands in $body.
 */
function chunkedHttp1Parser(string &$body, ?int $bodySizeLimit = null): Http1Parser
{
    // HttpStream's constructor is private; build a real one without a live connection.
    $stream = \Closure::bind(
        fn (): HttpStream => new HttpStream(
            new InternetAddress('127.0.0.1', 50000),
            new InternetAddress('127.0.0.1', 80),
            null,
            fn () => throw new \Error('Not used'),
            fn () => null,
        ),
        null,
        HttpStream::class,
    )();

    $request = new Request('http://example.com/');
    if ($bodySizeLimit !== null) {
        $request->setBodySizeLimit($bodySizeLimit);
    }

    // Walk the request to the phase the parser expects; push() is the transition that needs no Connection.
    events()->requestStart($request);
    events()->push($request);
    events()->requestHeaderStart($request, $stream);

    $parser = new Http1Parser(
        $request,
        $stream,
        function (string $data) use (&$body): Future {
            $body .= $data;

            return Future::complete();
        },
        new NullCancellation,
        fn () => null,
    );

    expect($parser->parse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n"))->toBeInstanceOf(Response::class);

    return $parser;
}

it('decodes a valid chunked body split across reads', function () {
    $body = '';
    $parser = chunkedHttp1Parser($body);

    $parser->parse("5\r\nhel");
    $parser->parse("lo\r");
    $parser->parse("\n0\r\n\r\n");

    expect($parser->isComplete())->toBeTrue()
        ->and($body)->toBe('hello');
});

it('rejects a chunk-size line that never ends instead of buffering it without bound', function () {
    $body = '';
    $parser = chunkedHttp1Parser($body);

    $parser->parse(str_repeat('a', 4096));
})->throws(ParseException::class, 'Chunk size line too long');

it('rejects chunk data that is not followed by CRLF', function () {
    $body = '';
    $parser = chunkedHttp1Parser($body);

    $parser->parse("5\r\nhelloXX0\r\n\r\n");
})->throws(ParseException::class, 'Chunk data not terminated by CRLF');

it('rejects a chunk size that does not fit in an integer', function (string $size) {
    $body = '';
    $parser = chunkedHttp1Parser($body);

    $parser->parse("{$size}\r\nhello");
})->with([
    '16 digits' => 'ffffffffffffffff',
    '17 digits' => '10000000000000000',
])->throws(ParseException::class, 'Chunk size too large');

it('accepts a chunk size with leading zeros', function () {
    $body = '';
    $parser = chunkedHttp1Parser($body);

    $parser->parse("0000000000000005\r\nhello\r\n0\r\n\r\n");

    expect($parser->isComplete())->toBeTrue()
        ->and($body)->toBe('hello');
});

it('bounds the largest accepted chunk size by the body limit', function () {
    $body = '';
    $parser = chunkedHttp1Parser($body, 4);

    $parser->parse("fffffffffffffff\r\nhello");
})->throws(ParseException::class, 'Configured body size exceeded');
