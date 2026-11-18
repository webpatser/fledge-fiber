<?php

use Fledge\Async\Future;
use Fledge\Async\Redis\RedisException;
use Fledge\Fiber\Redis\FledgeRedisConnector;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Revolt\EventLoop;

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

it('answers other fibers while a blpop is blocked on the same connection', function () {
    $conn = redisConnection();
    $key = 'fledge_blpop_'.uniqid();

    $pop = async(fn () => $conn->blpop($key, 2));
    delay(0.05);

    // Before the fix PING queued behind BLPOP on the shared socket and only
    // returned after BLPOP timed out, so the pop was already complete here.
    $pong = $conn->command('ping');
    $popDoneWhenPingReturned = $pop->isComplete();

    $conn->command('rpush', [$key, 'job']);

    expect($pong)->toBe('PONG')
        ->and($popDoneWhenPingReturned)->toBeFalse()
        ->and($pop->await())->toBe([$key, 'job']);

    $conn->command('del', [$key]);
    $conn->disconnect();
});

it('keeps at most two idle blocking connections after ten concurrent blpops', function () {
    $name = 'fledge_cap_'.uniqid();
    $conn = (new FledgeRedisConnector)->connect(redisConfig() + ['name' => $name], []);
    $key = 'fledge_blpop_cap_'.uniqid();

    $pops = [];

    for ($i = 0; $i < 10; $i++) {
        $pops[] = async(fn () => $conn->blpop($key, 5));
    }

    delay(0.1);
    $conn->command('rpush', [$key, ...range(1, 10)]);

    foreach ($pops as $pop) {
        expect($pop->await())->toHaveCount(2);
    }

    // The shared connection plus the idle dedicated ones carry the name. A
    // client that answered QUIT can still be listed until the server frees
    // it, so read the list again (bounded) until the count settles.
    for ($round = 0; $round < 50; $round++) {
        $clients = substr_count((string) $conn->command('client', ['LIST']), "name={$name} ");

        if ($clients <= 3) {
            break;
        }

        delay(0.01);
    }

    expect($clients)->toBe(3);

    $conn->disconnect();
});

// Expected shapes below were taken from phpredis 6.3.0 against this server.
it('returns phpredis-shaped replies from xread after xadd', function () {
    $conn = redisConnection();
    $a = 'fledge_xr_a_'.uniqid();
    $b = 'fledge_xr_b_'.uniqid();

    $conn->command('xadd', [$a, '1-0', 'f', 'a']);
    $conn->command('xadd', [$a, '2-0', 'f', 'b', 'g', 'c']);
    $conn->command('xadd', [$b, '1-0', 'x', 'y']);

    expect($conn->xread([$a => '0', $b => '0']))->toBe([
        $a => ['1-0' => ['f' => 'a'], '2-0' => ['f' => 'b', 'g' => 'c']],
        $b => ['1-0' => ['x' => 'y']],
    ])
        ->and($conn->xread([$a => '0'], 1))->toBe([$a => ['1-0' => ['f' => 'a']]])
        ->and($conn->xread([$a => '$']))->toBe([])
        ->and($conn->xread(['fledge_xr_missing_'.uniqid() => '0']))->toBe([]);

    $conn->command('del', [$a, $b]);
    $conn->disconnect();
});

it('returns phpredis-shaped replies from xreadgroup', function () {
    $conn = redisConnection();
    $key = 'fledge_xrg_'.uniqid();

    $conn->command('xadd', [$key, '1-0', 'f', 'a']);
    $conn->command('xadd', [$key, '2-0', 'f', 'b']);
    $conn->command('xgroup', ['CREATE', $key, 'g1', '0']);

    expect($conn->xreadgroup('g1', 'c1', [$key => '>']))->toBe([
        $key => ['1-0' => ['f' => 'a'], '2-0' => ['f' => 'b']],
    ])
        ->and($conn->xreadgroup('g1', 'c1', [$key => '>']))->toBe([])
        ->and($conn->xreadgroup('g1', 'c1', [$key => '0'], 1))->toBe([$key => ['1-0' => ['f' => 'a']]]);

    $conn->command('del', [$key]);
    $conn->disconnect();
});

it('returns an empty array when a blocking xread or xreadgroup times out', function () {
    $conn = redisConnection();
    $key = 'fledge_xr_to_'.uniqid();

    $conn->command('xadd', [$key, '1-0', 'f', 'a']);
    $conn->command('xgroup', ['CREATE', $key, 'g1', '$']);

    expect($conn->xread([$key => '$'], -1, 100))->toBe([])
        ->and($conn->xreadgroup('g1', 'c1', [$key => '>'], 1, 100))->toBe([]);

    $conn->command('del', [$key]);
    $conn->disconnect();
});

it('wakes a blocking xread with an xadd while the shared connection still answers', function () {
    $conn = redisConnection();
    $key = 'fledge_xr_wake_'.uniqid();
    $conn->command('xadd', [$key, '1-0', 'f', 'a']);

    $read = async(fn () => $conn->xread([$key => '$'], -1, 2000));
    delay(0.05);

    $pong = $conn->command('ping');
    $readDoneWhenPingReturned = $read->isComplete();

    $conn->command('xadd', [$key, '5-0', 'f', 'z']);

    expect($pong)->toBe('PONG')
        ->and($readDoneWhenPingReturned)->toBeFalse()
        ->and($read->await())->toBe([$key => ['5-0' => ['f' => 'z']]]);

    $conn->command('del', [$key]);
    $conn->disconnect();
});

it('fails a blpop in flight when another fiber disconnects, without swallowing a later push', function () {
    $conn = redisConnection();
    $other = redisConnection();
    $key = 'fledge_blpop_dc_'.uniqid();

    $pop = async(fn () => $conn->blpop($key, 0));
    delay(0.05);

    // Hang guard only: without the fix the pop waits until this push.
    $watchdog = EventLoop::delay(1, function () use ($other, $key): void {
        $other->command('rpush', [$key, 'watchdog']);
    });

    $conn->disconnect();

    try {
        expect(fn () => $pop->await())->toThrow(RedisException::class, 'connection lost');
    } finally {
        EventLoop::cancel($watchdog);
    }

    // The closed connection is off the server: nobody pops this one.
    $other->command('rpush', [$key, 'job']);
    delay(0.05);

    expect($other->command('lrange', [$key, 0, -1]))->toBe(['job']);

    $other->command('del', [$key]);
    $other->disconnect();
});

it('reconnects the shared connection into the database a select inside multi chose at exec', function () {
    $conn = redisConnection();
    $other = redisConnection();

    $conn->multi();
    $conn->select(3);
    $conn->exec();

    $other->command('client', ['KILL', 'ID', $conn->command('client', ['ID'])]);
    delay(0.05);

    expect($conn->command('client', ['INFO']))->toContain(' db=3 ');

    $conn->disconnect();
    $other->disconnect();
});

it('keeps the configured database after a select inside multi that fails at exec', function () {
    $conn = redisConnection();
    $key = 'fledge_multi_select_'.uniqid();
    $conn->command('rpush', [$key, 'job']);

    $conn->multi();
    $conn->select(9999);
    $replies = $conn->exec();

    expect($replies)->toBe([false])
        ->and($conn->blpop($key, 1))->toBe([$key, 'job']);

    $conn->command('del', [$key]);
    $conn->disconnect();
});
