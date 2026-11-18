<?php

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;

use function Fledge\Async\delay;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

function linkTestParameters(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379),
    ];
}

function linkTestLink(string $name): ReconnectingRedisLink
{
    return new ReconnectingRedisLink(createRedisConnector(RedisConfig::fromParameters(linkTestParameters() + ['name' => $name])));
}

function linkTestObserver(): RedisClient
{
    return createRedisClient(RedisConfig::fromParameters(linkTestParameters()));
}

/**
 * Number of server-side clients carrying the name once the count reached
 * the expected one (bounded wait, as a closed client can linger briefly),
 * read again a little later so a reconnect right after is still seen.
 */
function linkTestSettledClients(RedisClient $observer, string $name, int $expected): int
{
    for ($round = 0; $round < 50; $round++) {
        if (substr_count((string) $observer->execute('client', 'LIST'), "name={$name} ") === $expected) {
            break;
        }

        delay(0.01);
    }

    delay(0.1);

    return substr_count((string) $observer->execute('client', 'LIST'), "name={$name} ");
}

uses()->beforeEach(function () {
    $parameters = linkTestParameters();
    $sock = @fsockopen($parameters['host'], $parameters['port'], $errno, $errstr, 1);

    if (! $sock) {
        $this->markTestSkipped('Redis not available on port '.$parameters['port']);
    }

    fclose($sock);
});

it('does not reconnect after quit until the next command', function () {
    $name = 'fledge_link_quit_'.uniqid();
    $observer = linkTestObserver();
    $link = linkTestLink($name);

    expect($link->execute('ping', [])->unwrap())->toBe('PONG')
        ->and(linkTestSettledClients($observer, $name, 1))->toBe(1);

    $link->execute('quit', []);

    expect(linkTestSettledClients($observer, $name, 0))->toBe(0)
        ->and($link->execute('ping', [])->unwrap())->toBe('PONG')
        ->and(linkTestSettledClients($observer, $name, 1))->toBe(1);

    $link->execute('quit', []);
    $observer->quit();
});

it('does not reconnect after the server closed an idle connection until the next command', function () {
    $name = 'fledge_link_kill_'.uniqid();
    $observer = linkTestObserver();
    $link = linkTestLink($name);

    $id = $link->execute('client', ['ID'])->unwrap();
    $observer->execute('client', 'KILL', 'ID', $id);

    expect(linkTestSettledClients($observer, $name, 0))->toBe(0)
        ->and($link->execute('ping', [])->unwrap())->toBe('PONG')
        ->and(linkTestSettledClients($observer, $name, 1))->toBe(1);

    $link->execute('quit', []);
    $observer->quit();
});

it('reconnects into a database chosen with a successful select', function () {
    $link = linkTestLink('fledge_link_db_'.uniqid());

    expect($link->execute('select', [3])->unwrap())->toBe('OK');

    $link->closeConnection();

    expect($link->execute('client', ['INFO'])->unwrap())->toContain(' db=3 ');

    $link->execute('quit', []);
});

it('ignores a select that was only queued in a discarded multi when reconnecting', function () {
    $link = linkTestLink('fledge_link_queued_'.uniqid());

    $link->execute('multi', []);
    expect($link->execute('select', [5])->unwrap())->toBe('QUEUED');
    $link->execute('discard', []);

    $link->closeConnection();

    expect($link->execute('client', ['INFO'])->unwrap())->toContain(' db=0 ');

    $link->execute('quit', []);
});

it('keeps reconnecting after a failed select', function () {
    $link = linkTestLink('fledge_link_badselect_'.uniqid());

    expect(fn () => $link->execute('select', [9999])->unwrap())->toThrow(QueryException::class);

    $link->closeConnection();

    expect($link->execute('ping', [])->unwrap())->toBe('PONG')
        ->and($link->execute('client', ['INFO'])->unwrap())->toContain(' db=0 ');

    $link->execute('quit', []);
});
