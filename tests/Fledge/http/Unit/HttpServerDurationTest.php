<?php

declare(strict_types=1);

use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Driver\DefaultHttpDriverFactory;
use Fledge\Async\Http\Server\Driver\Http1Driver;
use Fledge\Async\Http\Server\Driver\Http2Driver;
use Fledge\Async\Http\Server\Driver\SocketClientFactory;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Http\Server\StaticContent\DocumentRoot;
use Fledge\Async\Stream\InternetAddress;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use Psr\Log\NullLogger;
use Time\Duration;

function httpServerDurationHandler(): ClosureRequestHandler
{
    return new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, [], 'ok'));
}

function httpServerDurationProperty(object $object, string $name): mixed
{
    return (new ReflectionProperty($object, $name))->getValue($object);
}

describe('Http1Driver', function () {
    it('rounds a Duration connection timeout up to whole seconds', function () {
        $driver = new Http1Driver(
            httpServerDurationHandler(),
            new DefaultErrorHandler,
            new NullLogger,
            connectionTimeout: Duration::fromMilliseconds(1500),
        );

        expect(httpServerDurationProperty($driver, 'connectionTimeout'))->toBe(2);
    });

    it('keeps an int connection timeout unchanged', function () {
        $driver = new Http1Driver(
            httpServerDurationHandler(),
            new DefaultErrorHandler,
            new NullLogger,
            connectionTimeout: 7,
        );

        expect(httpServerDurationProperty($driver, 'connectionTimeout'))->toBe(7);
    });
});

describe('Http2Driver', function () {
    it('rounds Duration timeouts up to whole seconds', function () {
        $driver = new Http2Driver(
            httpServerDurationHandler(),
            new DefaultErrorHandler,
            new NullLogger,
            streamTimeout: Duration::fromSeconds(3),
            connectionTimeout: Duration::fromMilliseconds(10_001),
        );

        expect(httpServerDurationProperty($driver, 'streamTimeout'))->toBe(3)
            ->and(httpServerDurationProperty($driver, 'connectionTimeout'))->toBe(11);
    });

    it('keeps int timeouts unchanged', function () {
        $driver = new Http2Driver(
            httpServerDurationHandler(),
            new DefaultErrorHandler,
            new NullLogger,
            streamTimeout: 4,
            connectionTimeout: 9,
        );

        expect(httpServerDurationProperty($driver, 'streamTimeout'))->toBe(4)
            ->and(httpServerDurationProperty($driver, 'connectionTimeout'))->toBe(9);
    });
});

describe('DefaultHttpDriverFactory', function () {
    it('hands Duration timeouts to the driver it creates', function () {
        $factory = new DefaultHttpDriverFactory(
            new NullLogger,
            streamTimeout: Duration::fromMilliseconds(1500),
            connectionTimeout: Duration::fromSeconds(20),
        );

        // A plain TCP client (no TLS, so no h2) gets an Http1Driver with the stream timeout as idle timeout.
        $client = new class implements Client
        {
            public function getId(): int
            {
                return 1;
            }

            public function getRemoteAddress(): SocketAddress
            {
                return new InternetAddress('127.0.0.1', 50000);
            }

            public function getLocalAddress(): SocketAddress
            {
                return new InternetAddress('127.0.0.1', 8080);
            }

            public function getTlsInfo(): ?TlsInfo
            {
                return null;
            }

            public function close(): void {}

            public function isClosed(): bool
            {
                return false;
            }

            public function onClose(\Closure $onClose): void {}
        };

        $driver = $factory->createHttpDriver(httpServerDurationHandler(), new DefaultErrorHandler, $client);

        expect($driver)->toBeInstanceOf(Http1Driver::class)
            ->and(httpServerDurationProperty($driver, 'connectionTimeout'))->toBe(2);
    });
});

describe('SocketClientFactory', function () {
    it('accepts a Duration and float seconds', function (Duration|float $timeout) {
        $factory = new SocketClientFactory(new NullLogger, $timeout);

        expect(httpServerDurationProperty($factory, 'tlsHandshakeTimeout'))->toEqual($timeout);
    })->with([
        'Duration' => fn () => Duration::fromMilliseconds(250),
        'float' => 0.25,
    ]);
});

describe('DocumentRoot::setExpiresPeriod()', function () {
    it('rounds a Duration up to whole seconds and clamps negatives to zero', function (Duration|int $period, int $expected) {
        $root = (new ReflectionClass(DocumentRoot::class))->newInstanceWithoutConstructor();

        $root->setExpiresPeriod($period);

        expect(httpServerDurationProperty($root, 'expiresPeriod'))->toBe($expected);
    })->with([
        'Duration' => [fn () => Duration::fromMilliseconds(1500), 2],
        'negated Duration' => [fn () => Duration::fromSeconds(5)->negate(), 0],
        'int' => [3600, 3600],
        'negative int' => [-5, 0],
    ]);
});
