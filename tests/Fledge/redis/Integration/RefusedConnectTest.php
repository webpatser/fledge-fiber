<?php

use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Fiber\Redis\FledgeRedisConnector;

use function Fledge\Async\Redis\createRedisConnector;

/*
 * A refused Redis connect fails at once, as phpredis does (one connect
 * attempt). The global socket connector retries a refused connect twice with
 * 2 s and 4 s of backoff; Redis connects must not go through it. Reconnect
 * retries belong to ReconnectingRedisLink and its own backoff (max_retries).
 * Port 1 on loopback refuses, so no Redis server is needed.
 */

function refusedRedisParameters(): array
{
    return ['host' => '127.0.0.1', 'port' => 1];
}

/**
 * Run the callback, return what it threw and how long it took in seconds.
 *
 * @return array{0: ?Throwable, 1: float}
 */
function refusedRedisMeasure(Closure $run): array
{
    $started = hrtime(true);
    $error = null;

    try {
        $run();
    } catch (Throwable $e) {
        $error = $e;
    }

    return [$error, (hrtime(true) - $started) / 1e9];
}

it('fails a refused connect at once with the same error text', function () {
    $connector = createRedisConnector(RedisConfig::fromParameters(refusedRedisParameters()));

    [$error, $elapsed] = refusedRedisMeasure(fn () => $connector->connect());

    expect($error)->toBeInstanceOf(RedisConnectionException::class)
        ->and($error->getMessage())->toBe('Failed to connect to redis instance (tcp://127.0.0.1:1)')
        ->and($error->getPrevious()?->getMessage())->not->toContain('attempts')
        ->and($elapsed)->toBeLessThan(1.0);
});

it('fails the first command on a refused Laravel connection at once', function () {
    $connection = (new FledgeRedisConnector)->connect(refusedRedisParameters(), []);

    [$error, $elapsed] = refusedRedisMeasure(fn () => $connection->command('get', ['fledge_refused']));

    expect($error)->toBeInstanceOf(RedisConnectionException::class)
        ->and($error->getMessage())->toBe('Failed to connect to redis instance (tcp://127.0.0.1:1)')
        ->and($elapsed)->toBeLessThan(1.0);
});

it('still reconnects with the link backoff when max_retries is set', function () {
    $connector = createRedisConnector(RedisConfig::fromParameters(refusedRedisParameters()));
    $link = new ReconnectingRedisLink($connector, null, new BackoffStrategy('constant', 0.15, 1.0), 2);

    [$error, $elapsed] = refusedRedisMeasure(fn () => $link->execute('ping', []));

    // Three connect attempts with two link backoffs of 0.15 s in between:
    // the link's own retry runs, the socket layer adds none.
    expect($error)->toBeInstanceOf(RedisConnectionException::class)
        ->and($error->getMessage())->toBe('Failed to connect to redis instance (tcp://127.0.0.1:1)')
        ->and($elapsed)->toBeGreaterThanOrEqual(0.28)
        ->and($elapsed)->toBeLessThan(1.0);
});
