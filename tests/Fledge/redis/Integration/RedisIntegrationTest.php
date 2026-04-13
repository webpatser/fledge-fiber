<?php

use Fledge\Fiber\Redis\FledgeRedisConnector;
use Fledge\Fiber\Redis\FledgeRedisConnection;

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
