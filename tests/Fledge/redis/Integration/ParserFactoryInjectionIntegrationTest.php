<?php

use Fledge\Async\Redis\Connection\SocketRedisConnector;
use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RespParser;
use Fledge\Async\Stream\ConnectContext;

/**
 * Test parser that wraps RespParser and counts bytes received per instance.
 * Used to prove that a custom factory only receives bytes for the connection
 * it was injected into, never bytes from a sibling connection.
 */
final class RecordingParser implements ParserInterface
{
    public int $bytesReceived = 0;

    private readonly RespParser $delegate;

    public function __construct(\Closure $push)
    {
        $this->delegate = new RespParser($push);
    }

    public function push(string $data): void
    {
        $this->bytesReceived += \strlen($data);
        $this->delegate->push($data);
    }

    public function cancel(): void
    {
        $this->delegate->cancel();
    }
}

function redisHost(): string
{
    return test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1');
}

function redisPort(): int
{
    return (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379);
}

function redisReachable(): bool
{
    $sock = @fsockopen(redisHost(), redisPort(), $errno, $errstr, 1);
    if (! $sock) {
        return false;
    }
    fclose($sock);

    return true;
}

uses()->beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not available on '.redisHost().':'.redisPort());
    }
});

it('uses a custom parser factory when provided', function () {
    $instances = [];
    $factory = function (\Closure $push) use (&$instances): ParserInterface {
        $parser = new RecordingParser($push);
        $instances[] = $parser;

        return $parser;
    };

    $connector = new SocketRedisConnector(
        uri: 'tcp://'.redisHost().':'.redisPort(),
        connectContext: new ConnectContext(),
        parserFactory: $factory,
    );

    $connection = $connector->connect();
    $connection->send('PING');
    $response = $connection->receive();

    expect($instances)->toHaveCount(1);
    expect($instances[0]->bytesReceived)->toBeGreaterThan(0);
    expect($response)->not->toBeNull();

    $connection->close();
});

it('falls back to RespParser when no factory is provided', function () {
    $connector = new SocketRedisConnector(
        uri: 'tcp://'.redisHost().':'.redisPort(),
        connectContext: new ConnectContext(),
    );

    $connection = $connector->connect();
    $connection->send('PING');
    $response = $connection->receive();

    expect($response)->not->toBeNull();

    $connection->close();
});

it('isolates parser factories per connection (race regression)', function () {
    // The bug we are guarding against: a process-wide static factory toggle
    // would leak between concurrent connections. With per-connection injection
    // each connection's factory is captured by closure and cannot be observed
    // by a sibling that did not opt in.
    $customInstances = [];
    $customFactory = function (\Closure $push) use (&$customInstances): ParserInterface {
        $parser = new RecordingParser($push);
        $customInstances[] = $parser;

        return $parser;
    };

    $defaultConnector = new SocketRedisConnector(
        uri: 'tcp://'.redisHost().':'.redisPort(),
        connectContext: new ConnectContext(),
    );

    $customConnector = new SocketRedisConnector(
        uri: 'tcp://'.redisHost().':'.redisPort(),
        connectContext: new ConnectContext(),
        parserFactory: $customFactory,
    );

    $defaultConnection = $defaultConnector->connect();
    $customConnection = $customConnector->connect();

    $defaultConnection->send('PING');
    $defaultResponse = $defaultConnection->receive();

    $customConnection->send('PING');
    $customResponse = $customConnection->receive();

    expect($customInstances)->toHaveCount(1);
    expect($customInstances[0]->bytesReceived)->toBeGreaterThan(0);
    expect($defaultResponse)->not->toBeNull();
    expect($customResponse)->not->toBeNull();

    $defaultConnection->close();
    $customConnection->close();
});
