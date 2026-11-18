<?php

use Fledge\Async\Future;
use Fledge\Fiber\Redis\FledgeRedisConnector;
use Fledge\Fiber\Redis\FledgeRedisConnection;

use function Fledge\Async\async;
use function Fledge\Async\delay;

function redisConfig(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379),
        'database' => 0,
    ];
}

function redisAvailable(): bool
{
    $host = test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1');
    $port = (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379);
    $sock = @fsockopen($host, $port, $errno, $errstr, 1);
    if (! $sock) {
        return false;
    }
    fclose($sock);

    return true;
}

function redisConnection(): FledgeRedisConnection
{
    $connector = new FledgeRedisConnector;

    return $connector->connect(redisConfig(), []);
}

/**
 * Publishes one message to every channel per round until $done is set or
 * the rounds run out, so a subscription that never sees a channel still ends.
 *
 * @param  array<int, string>  $channels
 */
function publishToChannelsUntilDone(array $channels, bool &$done): Future
{
    $publisher = redisConnection();

    return async(function () use ($publisher, $channels, &$done): void {
        for ($round = 0; $round < 60 && ! $done; $round++) {
            foreach ($channels as $channel) {
                $publisher->command('publish', [$channel, 'hello '.$channel]);
            }

            delay(0.05);
        }

        $publisher->disconnect();
    });
}

/**
 * Publishes a numbered sequence round-robin over the channels, one awaited
 * PUBLISH at a time so the server sees them in sequence order.
 *
 * @param  array<int, string>  $channels
 */
function publishSequenceUntilDone(array $channels, bool &$done): Future
{
    $publisher = redisConnection();

    return async(function () use ($publisher, $channels, &$done): void {
        for ($seq = 0; $seq < 120 && ! $done; $seq++) {
            $publisher->command('publish', [$channels[$seq % count($channels)], (string) $seq]);

            delay(0.01);
        }

        $publisher->disconnect();
    });
}

/**
 * Callback that suspends inside every call and records re-entrancy and the
 * order of what it saw; stops after 8 messages covering every channel.
 */
function sequentialSubscribeProbe(array &$seen, int &$maxInside, bool &$done): Closure
{
    $inside = 0;

    return function ($message, $channel) use (&$seen, &$maxInside, &$done, &$inside): void {
        $inside++;
        $maxInside = max($maxInside, $inside);

        delay(0.05);

        $seen[] = [$channel, (int) $message];
        $inside--;

        if (count($seen) >= 8 && count(array_unique(array_column($seen, 0))) >= 2) {
            $done = true;

            throw new RuntimeException('subscription done');
        }

        if (count($seen) >= 40) {
            $done = true;

            throw new RuntimeException('subscription done');
        }
    };
}

uses()->beforeEach(function () {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis not available on port '.test_env('FLEDGE_TEST_REDIS_PORT', 16379));
    }
});

it('connects and pings', function () {
    $conn = redisConnection();

    $result = $conn->command('ping');
    expect($result)->toBe('PONG');

    $conn->disconnect();
});

it('sets and gets a value', function () {
    $conn = redisConnection();

    $conn->set('fledge_test_key', 'hello_fledge');
    $value = $conn->get('fledge_test_key');

    expect($value)->toBe('hello_fledge');

    $conn->command('del', ['fledge_test_key']);
    $conn->disconnect();
});

it('deletes a key', function () {
    $conn = redisConnection();

    $conn->set('fledge_test_del', 'to_delete');
    $conn->command('del', ['fledge_test_del']);

    $value = $conn->get('fledge_test_del');
    expect($value)->toBeNull();

    $conn->disconnect();
});

it('executes pipeline with multiple keys', function () {
    $conn = redisConnection();

    $conn->pipeline(function ($pipe) {
        $pipe->set('fledge_pipe_1', 'a');
        $pipe->set('fledge_pipe_2', 'b');
        $pipe->set('fledge_pipe_3', 'c');
    });

    expect($conn->get('fledge_pipe_1'))->toBe('a');
    expect($conn->get('fledge_pipe_2'))->toBe('b');
    expect($conn->get('fledge_pipe_3'))->toBe('c');

    $conn->command('del', ['fledge_pipe_1', 'fledge_pipe_2', 'fledge_pipe_3']);
    $conn->disconnect();
});

it('increments and decrements', function () {
    $conn = redisConnection();

    $conn->set('fledge_counter', '10');

    $conn->command('incr', ['fledge_counter']);
    expect((int) $conn->get('fledge_counter'))->toBe(11);

    $conn->command('decr', ['fledge_counter']);
    expect((int) $conn->get('fledge_counter'))->toBe(10);

    $conn->command('del', ['fledge_counter']);
    $conn->disconnect();
});

it('delivers messages from every channel passed to subscribe', function () {
    $conn = redisConnection();
    $id = uniqid();
    $channels = ["fledge_sub_a_{$id}", "fledge_sub_b_{$id}"];
    $received = [];
    $count = 0;
    $done = false;

    $publishing = publishToChannelsUntilDone($channels, $done);

    // A regular closure, not fn (): an arrow function captures by value, so
    // the callback would fill a copy of $received instead of this one.
    expect(function () use ($conn, $channels, &$received, &$count, &$done): void {
        $conn->subscribe($channels, function ($message, $channel) use (&$received, &$count, &$done): void {
            $received[$channel] = $message;

            if (count($received) === 2 || ++$count >= 20) {
                $done = true;

                throw new RuntimeException('subscription done');
            }
        });
    })->toThrow(RuntimeException::class, 'subscription done');

    $done = true;
    $publishing->await();
    ksort($received);

    expect($received)->toBe([
        "fledge_sub_a_{$id}" => "hello fledge_sub_a_{$id}",
        "fledge_sub_b_{$id}" => "hello fledge_sub_b_{$id}",
    ]);

    $conn->disconnect();
});

it('delivers messages from every pattern passed to psubscribe with the matched channel', function () {
    $conn = redisConnection();
    $id = uniqid();
    $received = [];
    $count = 0;
    $done = false;

    $publishing = publishToChannelsUntilDone(["fledge_psub_a_{$id}.x", "fledge_psub_b_{$id}.y"], $done);

    expect(function () use ($conn, $id, &$received, &$count, &$done): void {
        $conn->psubscribe(["fledge_psub_a_{$id}.*", "fledge_psub_b_{$id}.*"], function ($message, $channel) use (&$received, &$count, &$done): void {
            $received[$channel] = $message;

            if (count($received) === 2 || ++$count >= 20) {
                $done = true;

                throw new RuntimeException('subscription done');
            }
        });
    })->toThrow(RuntimeException::class, 'subscription done');

    $done = true;
    $publishing->await();
    ksort($received);

    expect($received)->toBe([
        "fledge_psub_a_{$id}.x" => "hello fledge_psub_a_{$id}.x",
        "fledge_psub_b_{$id}.y" => "hello fledge_psub_b_{$id}.y",
    ]);

    $conn->disconnect();
});

it('runs the subscribe callback one message at a time in publish order across channels', function () {
    $conn = redisConnection();
    $id = uniqid();
    $channels = ["fledge_seq_a_{$id}", "fledge_seq_b_{$id}"];
    $seen = [];
    $maxInside = 0;
    $done = false;

    $publishing = publishSequenceUntilDone($channels, $done);
    $probe = sequentialSubscribeProbe($seen, $maxInside, $done);

    expect(function () use ($conn, $channels, $probe): void {
        $conn->subscribe($channels, $probe);
    })->toThrow(RuntimeException::class, 'subscription done');

    $done = true;
    $publishing->await();

    $sequence = array_column($seen, 1);
    $sorted = $sequence;
    sort($sorted);

    expect($maxInside)->toBe(1)
        ->and(array_unique(array_column($seen, 0)))->toHaveCount(2)
        ->and($sequence)->toBe($sorted)
        ->and(array_unique($sequence))->toBe($sequence);

    foreach ($seen as [$channel, $seq]) {
        expect($channel)->toBe($channels[$seq % 2]);
    }

    $conn->disconnect();
});

it('runs the psubscribe callback one message at a time in publish order across patterns', function () {
    $conn = redisConnection();
    $id = uniqid();
    $channels = ["fledge_pseq_a_{$id}.x", "fledge_pseq_b_{$id}.y"];
    $seen = [];
    $maxInside = 0;
    $done = false;

    $publishing = publishSequenceUntilDone($channels, $done);
    $probe = sequentialSubscribeProbe($seen, $maxInside, $done);

    expect(function () use ($conn, $id, $probe): void {
        $conn->psubscribe(["fledge_pseq_a_{$id}.*", "fledge_pseq_b_{$id}.*"], $probe);
    })->toThrow(RuntimeException::class, 'subscription done');

    $done = true;
    $publishing->await();

    $sequence = array_column($seen, 1);
    $sorted = $sequence;
    sort($sorted);

    expect($maxInside)->toBe(1)
        ->and(array_unique(array_column($seen, 0)))->toHaveCount(2)
        ->and($sequence)->toBe($sorted)
        ->and(array_unique($sequence))->toBe($sequence);

    foreach ($seen as [$channel, $seq]) {
        expect($channel)->toBe($channels[$seq % 2]);
    }

    $conn->disconnect();
});
