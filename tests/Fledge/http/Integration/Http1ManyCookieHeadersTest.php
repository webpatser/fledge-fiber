<?php

use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;

require_once __DIR__.'/../Fixtures/raw_http1.php';

function cookieEchoHandler(): ClosureRequestHandler
{
    return new ClosureRequestHandler(function ($request): ServerResponse {
        // An unrelated header sharing the "cookie" prefix must not touch the parsed cookies.
        $request->removeHeader('cookie2');

        $cookies = [];
        foreach ($request->getCookies() as $name => $cookie) {
            $cookies[$name] = $cookie->getValue();
        }

        return new ServerResponse(200, [], 'lines:'.count($request->getHeaderArray('cookie')).';cookies:'.json_encode($cookies));
    });
}

it('parses thousands of cookie header lines once and quickly', function () {
    [$server, $port] = startHttp1RawServer(cookieEchoHandler());

    try {
        $lines = 2700;
        $expected = [];
        $payload = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n";

        for ($i = 0; $i < $lines; $i++) {
            $name = chr(97 + $i % 26);
            $value = (string) ($i % 10);
            $expected[$name] = $value;
            $payload .= "Cookie:{$name}={$value}\r\n";
        }

        $payload .= "\r\n";

        $start = hrtime(true);
        $response = rawHttp1Exchange($port, $payload);
        $elapsed = (hrtime(true) - $start) / 1e9;

        ksort($expected);
        preg_match('/cookies:(\{.*\})/', $response, $m);
        $actual = json_decode($m[1] ?? '{}', true);
        ksort($actual);

        expect(rawHttp1Status($response))->toBe(200)
            ->and($response)->toContain("lines:{$lines};")
            ->and($actual)->toBe($expected)
            ->and($elapsed)->toBeLessThan(1.0);
    } finally {
        $server->stop();
    }
});
