<?php

use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Fiber\Http\FledgeGuzzle;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;

use function Fledge\Async\delay;

require_once __DIR__.'/../Fixtures/loopback.php';

afterEach(fn () => FledgeGuzzle::flush());

it('builds fresh stacks over one shared factory per limit', function () {
    expect(FledgeGuzzle::stack())->toBeInstanceOf(HandlerStack::class)
        ->and(FledgeGuzzle::stack())->not->toBe(FledgeGuzzle::stack())
        ->and(FledgeGuzzle::factory())->toBe(FledgeGuzzle::factory())
        ->and(FledgeGuzzle::factory(2))->not->toBe(FledgeGuzzle::factory());
});

it('runs requests concurrently and honors the per-host limit', function () {
    [$server, $port] = startLoopbackServer(null, function (): ServerResponse {
        delay(0.2);

        return new ServerResponse(200, [], 'ok');
    });

    try {
        $options = ['timeout' => LOOPBACK_TIMEOUT, 'connect_timeout' => LOOPBACK_TIMEOUT];

        $timed = function (Client $client) use ($port): float {
            $start = microtime(true);

            $responses = Utils::unwrap([
                $client->getAsync("http://127.0.0.1:{$port}/"),
                $client->getAsync("http://127.0.0.1:{$port}/"),
            ]);

            expect((string) $responses[0]->getBody())->toBe('ok')
                ->and((string) $responses[1]->getBody())->toBe('ok');

            return microtime(true) - $start;
        };

        expect($timed(FledgeGuzzle::client($options)))->toBeLessThan(0.35)
            ->and($timed(FledgeGuzzle::client($options, 1)))->toBeGreaterThanOrEqual(0.4);
    } finally {
        $server->stop();
    }
});
