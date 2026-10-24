<?php

use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Fiber\Redis\FledgeRedisConnection;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Records the order in which commands reach the link (the real link enqueues
 * synchronously, so this is the wire order) and suspends on every reply so
 * concurrent fibers get a chance to interleave.
 */
final class OrderRecordingLink implements RedisLink
{
    /** @var list<string> */
    public array $log = [];

    private bool $inMulti = false;

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);
        $this->log[] = trim($command.' '.implode(' ', $parameters));

        $reply = match (true) {
            $command === 'MULTI' => 'OK',
            $command === 'EXEC', $command === 'DISCARD' => 'OK',
            $this->inMulti => 'QUEUED',
            default => 'OK',
        };

        $this->inMulti = match ($command) {
            'MULTI' => true,
            'EXEC', 'DISCARD' => false,
            default => $this->inMulti,
        };

        delay(0.005);

        return new RedisValue($reply);
    }
}

/**
 * Collect the commands sent between each MULTI and its EXEC/DISCARD.
 */
function commandsInsideTransaction(array $log): array
{
    $inside = [];
    $open = false;

    foreach ($log as $entry) {
        $command = strtok($entry, ' ');

        if ($command === 'MULTI') {
            $open = true;
            $inside[] = [];

            continue;
        }

        if ($command === 'EXEC' || $command === 'DISCARD') {
            $open = false;

            continue;
        }

        if ($open) {
            $inside[array_key_last($inside)][] = $entry;
        }
    }

    return $inside;
}

it('keeps commands from other fibers out of an open multi/exec block', function () {
    $link = new OrderRecordingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link));

    $a = async(function () use ($connection) {
        $connection->multi();
        $connection->command('set', ['a1', 'x']);
        delay(0.02);
        $connection->command('set', ['a2', 'y']);

        return $connection->exec();
    });

    $b = async(function () use ($connection) {
        delay(0.001);

        return [
            $connection->command('get', ['b1']),
            $connection->command('incr', ['b2']),
        ];
    });

    [, $bReplies] = await([$a, $b]);

    expect(commandsInsideTransaction($link->log))->toBe([['SET a1 x', 'SET a2 y']])
        ->and($bReplies)->toBe(['OK', 'OK'])
        ->and($link->log)->toContain('GET b1', 'INCR b2');
});

it('serializes two concurrent transactions', function () {
    $link = new OrderRecordingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link));

    $tx = fn (string $key) => async(function () use ($connection, $key) {
        return $connection->transaction(function ($redis) use ($key) {
            $redis->command('set', [$key.'1', 'v']);
            delay(0.01);
            $redis->command('set', [$key.'2', 'v']);
        });
    });

    await([$tx('a'), $tx('b')]);

    expect(commandsInsideTransaction($link->log))->toBe([
        ['SET a1 v', 'SET a2 v'],
        ['SET b1 v', 'SET b2 v'],
    ]);
});

it('releases the transaction lock when the callback throws', function () {
    $link = new OrderRecordingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link));

    $failing = async(fn () => $connection->transaction(function ($redis) {
        $redis->command('set', ['a', 'v']);

        throw new RuntimeException('boom');
    }));

    $other = async(function () use ($connection) {
        delay(0.001);

        return $connection->command('get', ['b']);
    });

    expect(fn () => $failing->await())->toThrow(RuntimeException::class, 'boom')
        ->and($other->await())->toBe('OK')
        ->and($connection->ownsTransaction())->toBeFalse()
        ->and(commandsInsideTransaction($link->log))->toBe([['SET a v']])
        ->and($link->log)->toContain('DISCARD');
});

it('runs a pipeline inside the owning fiber transaction without deadlocking', function () {
    $link = new OrderRecordingLink;
    $connection = new FledgeRedisConnection(new RedisClient($link));

    $result = async(function () use ($connection) {
        $connection->multi();
        $connection->pipeline()->set('p1', 'v')->set('p2', 'v')->exec();

        return $connection->exec();
    })->await();

    expect($result)->toBe('OK')
        ->and(commandsInsideTransaction($link->log))->toBe([['SET p1 v', 'SET p2 v']]);
});

it('does not hold a lock when not in a transaction', function () {
    $connection = new FledgeRedisConnection(new RedisClient(new OrderRecordingLink));

    expect($connection->ownsTransaction())->toBeFalse();

    $connection->multi();
    expect($connection->ownsTransaction())->toBeTrue();

    $connection->discard();
    expect($connection->ownsTransaction())->toBeFalse();
});

it('releases the transaction lock when a command inside multi fails on the connection', function () {
    $link = new class implements RedisLink
    {
        public array $log = [];

        public function execute(string $command, array $parameters): RedisResponse
        {
            $command = strtoupper($command);
            $this->log[] = $command;
            delay(0.001);

            if ($command === 'SETEX') {
                throw new RedisConnectionException('Connection lost');
            }

            return new RedisValue('OK');
        }
    };
    $connection = new FledgeRedisConnection(new RedisClient($link));

    // Mirrors RedisStore::putMany(): multi(), a failing command, exec() never reached.
    $failing = async(function () use ($connection) {
        $connection->multi();
        $connection->command('setex', ['a', 10, 'v']);
        $connection->exec();
    });

    $other = async(function () use ($connection) {
        delay(0.0005);

        return $connection->command('get', ['b']);
    });

    expect(fn () => $failing->await())->toThrow(RedisConnectionException::class)
        ->and($other->await(new Fledge\Async\TimeoutCancellation(1)))->toBe('OK')
        ->and($connection->ownsTransaction())->toBeFalse()
        ->and($link->log)->toBe(['MULTI', 'SETEX', 'DISCARD', 'GET']);
});
