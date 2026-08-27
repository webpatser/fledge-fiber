<?php

use Fledge\Async\Http\Server\Response as ServerResponse;
use GuzzleHttp\Promise\Utils;

require_once __DIR__.'/../Fixtures/loopback.php';

it('defers the request by the delay option without blocking the loop', function () {
    [$server, $port] = startLoopbackServer(null, fn (): ServerResponse => new ServerResponse(200, [], 'delayed ok'));

    try {
        $client = makeGuzzleClient();

        $start = microtime(true);
        $response = $client->get("http://127.0.0.1:{$port}/", ['delay' => 200]);
        $single = microtime(true) - $start;

        expect($response->getStatusCode())->toBe(200)
            ->and($single)->toBeGreaterThanOrEqual(0.2);

        // Two delayed requests wait concurrently: the total stays near one
        // delay instead of doubling.
        $start = microtime(true);

        Utils::unwrap([
            $client->getAsync("http://127.0.0.1:{$port}/", ['delay' => 200]),
            $client->getAsync("http://127.0.0.1:{$port}/", ['delay' => 200]),
        ]);

        $both = microtime(true) - $start;

        expect($both)->toBeGreaterThanOrEqual(0.2)
            ->and($both)->toBeLessThan(0.45);
    } finally {
        $server->stop();
    }
});
