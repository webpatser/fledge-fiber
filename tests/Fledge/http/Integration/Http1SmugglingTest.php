<?php

use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;

require_once __DIR__.'/../Fixtures/raw_http1.php';

function echoBodyHandler(): ClosureRequestHandler
{
    return new ClosureRequestHandler(fn ($request): ServerResponse => new ServerResponse(200, [], 'body:'.$request->getBody()->buffer()));
}

it('rejects a request carrying both content-length and transfer-encoding with 400', function () {
    [$server, $port] = startHttp1RawServer(echoBodyHandler());

    try {
        $response = rawHttp1Exchange($port, "POST / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n");

        expect(rawHttp1Status($response))->toBe(400);
    } finally {
        $server->stop();
    }
});

it('still accepts a plain chunked request', function () {
    [$server, $port] = startHttp1RawServer(echoBodyHandler());

    try {
        $response = rawHttp1Exchange($port, "POST / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n");

        expect(rawHttp1Status($response))->toBe(200)->and($response)->toContain('body:abc');
    } finally {
        $server->stop();
    }
});
