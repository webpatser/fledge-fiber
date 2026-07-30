<?php

use Fledge\Async\Cancellation;
use Fledge\Async\Redis\Connection\Authenticator;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;

final class RecordingRedisConnection implements RedisConnection
{
    /** @var list<list<string>> */
    public array $sent = [];

    public function receive(): ?RedisResponse
    {
        return new RedisValue('OK');
    }

    public function send(string ...$args): void
    {
        $this->sent[] = $args;
    }

    public function getName(): string
    {
        return 'tcp://localhost:6379';
    }

    public function reference(): void {}

    public function unreference(): void {}

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}

final class StubRedisConnector implements RedisConnector
{
    public function __construct(private RedisConnection $connection) {}

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        return $this->connection;
    }
}

it('sends single-argument AUTH when no username is configured', function () {
    $connection = new RecordingRedisConnection;

    (new Authenticator('secret', new StubRedisConnector($connection)))->connect();

    expect($connection->sent)->toBe([['AUTH', 'secret']]);
});

it('sends two-argument AUTH for an acl username', function () {
    $connection = new RecordingRedisConnection;

    (new Authenticator('secret', new StubRedisConnector($connection), 'alice'))->connect();

    expect($connection->sent)->toBe([['AUTH', 'alice', 'secret']]);
});
