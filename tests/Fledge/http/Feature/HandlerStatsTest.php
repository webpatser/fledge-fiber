<?php

use Fledge\Async\Http\Server\Response as ServerResponse;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\TransferStats;

use function Fledge\Async\delay;

require_once __DIR__.'/../Fixtures/loopback.php';

it('fires on_stats with transfer details on success', function () {
    [$server, $port] = startLoopbackServer(null, fn (): ServerResponse => new ServerResponse(200, [], 'stats ok'));

    try {
        $captured = null;

        makeGuzzleClient()->get("http://127.0.0.1:{$port}/", [
            'on_stats' => function (TransferStats $stats) use (&$captured) {
                $captured = $stats;
            },
        ]);

        $handlerStats = $captured->getHandlerStats();

        expect($captured)->toBeInstanceOf(TransferStats::class)
            ->and($captured->getTransferTime())->toBeGreaterThan(0.0)
            ->and($handlerStats['http_code'])->toBe(200)
            ->and($handlerStats['connect_time'])->toBeGreaterThan(0.0)
            ->and($handlerStats['pretransfer_time'])->toBeGreaterThan(0.0)
            ->and($handlerStats['starttransfer_time'])->toBeGreaterThanOrEqual($handlerStats['pretransfer_time'])
            ->and($handlerStats['primary_ip'])->toBe('127.0.0.1')
            ->and($handlerStats['primary_port'])->toBe($port)
            ->and($handlerStats['size_download'])->toBe(strlen('stats ok'))
            ->and($handlerStats['url'])->toBe("http://127.0.0.1:{$port}/")
            ->and($handlerStats['handler'])->toBe('fledge');
    } finally {
        $server->stop();
    }
});

it('fires on_stats with the error on failure', function () {
    $port = refusedPort();
    $captured = null;

    try {
        makeGuzzleClient(['connect_timeout' => 2.0])->get("http://127.0.0.1:{$port}/", [
            'on_stats' => function (TransferStats $stats) use (&$captured) {
                $captured = $stats;
            },
        ]);

        $this->fail('Expected the refused connection to throw');
    } catch (\GuzzleHttp\Exception\ConnectException) {
        expect($captured)->toBeInstanceOf(TransferStats::class)
            ->and($captured->getHandlerErrorData())->toBeInstanceOf(\GuzzleHttp\Exception\ConnectException::class)
            ->and($captured->getHandlerStats()['http_code'])->toBe(0);
    }
});

it('reports sane per-request timings for concurrent requests', function () {
    [$server, $port] = startLoopbackServer(null, function (): ServerResponse {
        delay(0.2);

        return new ServerResponse(200, [], 'slow ok');
    });

    try {
        $client = makeGuzzleClient();
        $stats = [];
        $options = [
            'on_stats' => function (TransferStats $transferStats) use (&$stats) {
                $stats[] = $transferStats;
            },
        ];

        $wallStart = microtime(true);

        Utils::unwrap([
            $client->getAsync("http://127.0.0.1:{$port}/", $options),
            $client->getAsync("http://127.0.0.1:{$port}/", $options),
        ]);

        $wallTime = microtime(true) - $wallStart;

        expect($stats)->toHaveCount(2)
            // Both requests overlapped on the loop.
            ->and($wallTime)->toBeLessThan(0.45)
            // Each request reports its own full duration, not near-zero.
            ->and($stats[0]->getTransferTime())->toBeGreaterThanOrEqual(0.15)
            ->and($stats[1]->getTransferTime())->toBeGreaterThanOrEqual(0.15);
    } finally {
        $server->stop();
    }
});
