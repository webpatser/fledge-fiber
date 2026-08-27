<?php

use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Fledge\Fiber\Redis\FledgeRedisConnection;

/**
 * Fails the first N executions with the given message, then succeeds.
 */
final class FlakyRetryLink implements RedisLink
{
    public int $calls = 0;

    public function __construct(
        private readonly int $failures,
        private readonly string $message = 'redis went away',
    ) {
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $this->calls++;

        if ($this->calls <= $this->failures) {
            throw new RedisException($this->message);
        }

        return new RedisValue('OK');
    }
}

function retryConnection(FlakyRetryLink $link, array $config = [], ?int &$rebuilds = null): FledgeRedisConnection
{
    $rebuilds = 0;

    return new FledgeRedisConnection(
        new RedisClient($link),
        null,
        function () use ($link, &$rebuilds) {
            $rebuilds++;

            return new RedisClient($link);
        },
        $config,
    );
}

it('honors command_retries for non-retryable commands', function () {
    $link = new FlakyRetryLink(2);
    $connection = retryConnection($link, ['command_retries' => 2], $rebuilds);

    expect($connection->command('incr', ['counter']))->toBe('OK')
        ->and($link->calls)->toBe(3)
        ->and($rebuilds)->toBe(2);
});

it('does not retry non-retryable commands without command_retries', function () {
    $link = new FlakyRetryLink(1);
    $connection = retryConnection($link, [], $rebuilds);

    expect(fn () => $connection->command('incr', ['counter']))->toThrow(RedisException::class)
        ->and($link->calls)->toBe(1)
        ->and($rebuilds)->toBe(1);
});

it('gives retryable commands one free retry on transient errors', function () {
    $link = new FlakyRetryLink(1);
    $connection = retryConnection($link, [], $rebuilds);

    expect($connection->command('get', ['key']))->toBe('OK')
        ->and($link->calls)->toBe(2);
});

it('treats a read timeout message as transient', function () {
    $link = new FlakyRetryLink(1, 'Redis read error on connection: no response to GET within 0.500 seconds');
    $connection = retryConnection($link, [], $rebuilds);

    expect($connection->command('get', ['key']))->toBe('OK')
        ->and($link->calls)->toBe(2);
});

it('does not retry on non-transient errors', function () {
    $link = new FlakyRetryLink(1, 'WRONGTYPE Operation against a key holding the wrong kind of value');
    $connection = retryConnection($link, ['command_retries' => 5], $rebuilds);

    expect(fn () => $connection->command('get', ['key']))->toThrow(RedisException::class)
        ->and($link->calls)->toBe(1)
        ->and($rebuilds)->toBe(0);
});

it('gives up after exhausting command_retries', function () {
    $link = new FlakyRetryLink(10);
    $connection = retryConnection($link, ['command_retries' => 2], $rebuilds);

    expect(fn () => $connection->command('incr', ['counter']))->toThrow(RedisException::class)
        ->and($link->calls)->toBe(3);
});
