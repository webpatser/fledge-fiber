<?php

declare(strict_types=1);

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Parallel\Context\ProcessContextFactory;
use Fledge\Async\Parallel\Context\ThreadContextFactory;
use Fledge\Async\Parallel\Ipc\LocalIpcHub;
use Fledge\Async\Sync\LocalSemaphore;
use Fledge\Async\Sync\RateLimitingSemaphore;
use Time\Duration;

use function Fledge\Async\delay;

describe('RateLimitingSemaphore', function () {
    it('accepts a Duration lock period and holds the lock for that long', function () {
        $semaphore = new RateLimitingSemaphore(new LocalSemaphore(1), Duration::fromMilliseconds(30));

        $semaphore->acquire()->release();

        $start = \hrtime(true);
        $semaphore->acquire()->release();

        expect((\hrtime(true) - $start) / 1e6)->toBeGreaterThanOrEqual(25.0);
    });

    it('keeps float seconds unchanged', function () {
        $semaphore = new RateLimitingSemaphore(new LocalSemaphore(1), 0.03);

        $semaphore->acquire()->release();

        $start = \hrtime(true);
        $semaphore->acquire()->release();

        expect((\hrtime(true) - $start) / 1e6)->toBeGreaterThanOrEqual(25.0);
    });

    it('rejects a negated Duration like a negative float', function (Duration|float $period) {
        expect(fn () => new RateLimitingSemaphore(new LocalSemaphore(1), $period))
            ->toThrow(\ValueError::class, 'The lock period must be greater than 0');
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);
});

describe('LocalIpcHub', function () {
    it('accepts a Duration key receive timeout', function () {
        $hub = new LocalIpcHub(Duration::fromMilliseconds(250));

        expect($hub->isClosed())->toBeFalse();

        $hub->close();
    });

    it('keeps float seconds unchanged', function () {
        $hub = new LocalIpcHub(0.25);

        expect($hub->isClosed())->toBeFalse();

        $hub->close();
    });
});

describe('context factories', function () {
    it('accept a Duration connect timeout', function () {
        expect(new ProcessContextFactory(childConnectTimeout: Duration::fromMilliseconds(1500)))
            ->toBeInstanceOf(ProcessContextFactory::class)
            ->and(new ThreadContextFactory(Duration::fromSeconds(2)))
            ->toBeInstanceOf(ThreadContextFactory::class);
    });
});

describe('MysqlConnectionPool', function () {
    it('accepts a Duration idle and wait timeout and exposes plain numbers', function () {
        $pool = new MysqlConnectionPool(
            new MysqlConfig('localhost'),
            idleTimeout: Duration::fromMilliseconds(1500),
            waitTimeout: Duration::fromMilliseconds(250),
        );

        expect($pool->getIdleTimeout())->toBe(2)
            ->and($pool->getWaitTimeout())->toBe(0.25);

        $pool->close();
    });

    it('keeps int and float values unchanged', function () {
        $pool = new MysqlConnectionPool(new MysqlConfig('localhost'), idleTimeout: 30, waitTimeout: 1.5);

        expect($pool->getIdleTimeout())->toBe(30)
            ->and($pool->getWaitTimeout())->toBe(1.5);

        $pool->close();
    });

    it('rounds a sub-second idle Duration up to one second', function () {
        $pool = new MysqlConnectionPool(new MysqlConfig('localhost'), idleTimeout: Duration::fromMilliseconds(1));

        expect($pool->getIdleTimeout())->toBe(1);

        $pool->close();
    });

    it('rejects a zero or negated idle Duration like a zero int', function (Duration|int $timeout) {
        expect(fn () => new MysqlConnectionPool(new MysqlConfig('localhost'), idleTimeout: $timeout))
            ->toThrow(\Error::class, 'The idle timeout must be 1 or greater');
    })->with([
        'zero Duration' => fn () => Duration::fromSeconds(0),
        'negated sub-second Duration' => fn () => Duration::fromMilliseconds(300)->negate(),
        'negated Duration' => fn () => Duration::fromSeconds(5)->negate(),
        'zero' => 0,
    ]);

    it('rejects a negated wait Duration like a negative float', function (Duration|float $timeout) {
        expect(fn () => new MysqlConnectionPool(new MysqlConfig('localhost'), waitTimeout: $timeout))
            ->toThrow(\Error::class, 'The wait timeout must be greater than 0');
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);
});
