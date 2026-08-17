<?php

use Fledge\Async\Cancellation;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Connection\RedisWireException;
use Fledge\Async\Redis\Protocol\RedisResponse;

use function Fledge\Async\delay;

/*
 * A deterministic receive failure (e.g. a wire parse error) must not turn
 * ReconnectingRedisLink into a reconnect storm. Before the fix the loop
 * reconnected immediately and resent the same queued commands, producing
 * thousands of connections per second until the local ephemeral port range
 * was exhausted (seen live via the resp3 nested-null parse bug: ~16k
 * TIME_WAIT sockets to 127.0.0.1:6379 from a single test run). Now a
 * RedisWireException fails the pending commands instead of resending them,
 * and reconnect attempts back off exponentially.
 */

final class WireFailingConnection implements RedisConnection
{
    public function receive(): ?RedisResponse
    {
        throw new RedisWireException('Redis wire parse failed: synthetic');
    }

    public function send(string ...$args): void
    {
    }

    public function getName(): string
    {
        return 'wire-failing';
    }

    public function reference(): void
    {
    }

    public function unreference(): void
    {
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void
    {
    }
}

final class CountingWireFailConnector implements RedisConnector
{
    public int $connects = 0;

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $this->connects++;

        return new WireFailingConnection();
    }
}

it('fails pending commands with the wire exception instead of resending them', function () {
    $link = new ReconnectingRedisLink(new CountingWireFailConnector());

    try {
        $link->execute('GET', ['key']);
        $this->fail('Expected RedisWireException to be thrown.');
    } catch (RedisWireException $e) {
        expect($e->getMessage())->toContain('synthetic');
    }
});

it('backs off between reconnect attempts instead of storming', function () {
    $connector = new CountingWireFailConnector();
    $link = new ReconnectingRedisLink($connector);

    try {
        $link->execute('GET', ['key']);
    } catch (RedisWireException) {
        // Expected; the loop keeps running for future commands.
    }

    // Give the reconnect loop a window. Without backoff it managed thousands
    // of connections per second; with exponential backoff (0.1s, 0.2s, ...)
    // it fits at most a handful into this window.
    delay(0.35);

    expect($connector->connects)->toBeLessThanOrEqual(5);
});
