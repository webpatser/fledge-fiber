<?php

declare(strict_types=1);

use Fledge\Async\Dns\DnsConfig;
use Fledge\Async\Dns\DnsConfigException;
use Fledge\Async\Stream\UnlimitedSocketPool;
use Fledge\Async\WebSocket\Client\Rfc6455ConnectionFactory;
use Fledge\Async\WebSocket\Client\WebsocketHandshake;
use Fledge\Async\WebSocket\PeriodicHeartbeatQueue;
use Fledge\Async\WebSocket\Server\Rfc6455ClientFactory;
use Time\Duration;

describe('DnsConfig::withTimeout()', function () {
    it('accepts a Duration and behaves like the same float', function () {
        $config = new DnsConfig(['127.0.0.1:53']);

        expect($config->withTimeout(Duration::fromMilliseconds(1500))->getTimeout())->toBe(1.5)
            ->and($config->withTimeout(1.5)->getTimeout())->toBe(1.5);
    });

    it('rejects a negated Duration like a negative float', function (Duration|float $timeout) {
        $config = new DnsConfig(['127.0.0.1:53']);

        expect(fn () => $config->withTimeout($timeout))->toThrow(DnsConfigException::class);
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);
});

describe('WebsocketHandshake::withTlsHandshakeTimeout()', function () {
    it('accepts a Duration and keeps float seconds unchanged', function () {
        $handshake = new WebsocketHandshake('ws://example.test/');

        expect($handshake->withTlsHandshakeTimeout(Duration::fromMilliseconds(2500))->getTlsHandshakeTimeout())->toBe(2.5)
            ->and($handshake->withTlsHandshakeTimeout(2.5)->getTlsHandshakeTimeout())->toBe(2.5);
    });
});

describe('PeriodicHeartbeatQueue', function () {
    it('accepts a Duration, rounded up to whole seconds', function () {
        expect(new PeriodicHeartbeatQueue(heartbeatPeriod: Duration::fromMilliseconds(1500)))
            ->toBeInstanceOf(PeriodicHeartbeatQueue::class);
    });

    it('rejects a negated Duration like a non-positive int', function (Duration|int $period) {
        expect(fn () => new PeriodicHeartbeatQueue(heartbeatPeriod: $period))
            ->toThrow(\ValueError::class, 'Heartbeat period must be greater than 0');
    })->with([
        'negated Duration' => fn () => Duration::fromSeconds(5)->negate(),
        'negated sub-second Duration' => fn () => Duration::fromMilliseconds(300)->negate(),
        'zero Duration' => fn () => Duration::fromSeconds(0),
        'zero' => 0,
        'negative int' => -1,
    ]);
});

describe('constructors accept a Duration', function () {
    it('builds the pool and the websocket factories', function () {
        expect(new UnlimitedSocketPool(Duration::fromSeconds(5)))->toBeInstanceOf(UnlimitedSocketPool::class)
            ->and(new Rfc6455ConnectionFactory(closePeriod: Duration::fromSeconds(2)))
            ->toBeInstanceOf(Rfc6455ConnectionFactory::class)
            ->and(new Rfc6455ClientFactory(closePeriod: Duration::fromSeconds(2)))
            ->toBeInstanceOf(Rfc6455ClientFactory::class);
    });
});
