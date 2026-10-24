<?php

use Fledge\Async\Http\Http2\Http2Parser;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;

require_once __DIR__.'/../Fixtures/raw_http1.php';
require_once __DIR__.'/../Fixtures/h2raw.php';

it('answers 400 to an HTTP/1 header value HttpMessage refuses', function () {
    [$server, $port] = startHttp1RawServer(new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, [], 'ok')));

    try {
        // Rfc7230 accepts the byte 0xFF in a field value, HttpMessage does not.
        $response = rawHttp1Exchange($port, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nX-Test: a\xffb\r\n\r\n");

        expect(rawHttp1Status($response))->toBe(400);
    } finally {
        $server->stop();
    }
});

it('resets only the HTTP/2 stream whose header value HttpMessage refuses', function (bool $endStream) {
    $peer = startH2Driver();

    try {
        $peer->openStream(1, [['x-test', "a\r\nb"]], $endStream);

        expect($peer->resetCode(1))->toBe(Http2Parser::PROTOCOL_ERROR);

        // The connection survives: a PING still gets its ACK.
        $peer->sendFrame(Http2Parser::PING, 0, 0, '12345678');
        $ack = $peer->waitFor(fn (array $f) => $f['type'] === Http2Parser::PING && ($f['flags'] & Http2Parser::ACK) !== 0);

        expect($ack)->not->toBeNull();
    } finally {
        $peer->close();
    }
})->with(['without body' => true, 'with body' => false])->group('Http2');
