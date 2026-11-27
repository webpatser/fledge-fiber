<?php

declare(strict_types=1);

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Connection\RedisTimeoutException;
use Fledge\Async\Redis\Connection\RetryPolicy;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\Redis\Sync\RedisMutexOptions;
use Time\Duration;

describe('RedisConfig', function () {
    it('accepts a Duration in fromUri() and withTimeout()', function () {
        $config = RedisConfig::fromUri('tcp://localhost:6379', Duration::fromMilliseconds(1500));

        expect($config->getTimeout())->toBe(1.5)
            ->and($config->withTimeout(Duration::fromSeconds(2))->getTimeout())->toBe(2.0);
    });

    it('keeps float seconds unchanged', function () {
        $config = RedisConfig::fromUri('tcp://localhost:6379', 2.5);

        expect($config->getTimeout())->toBe(2.5)
            ->and($config->withTimeout(0.25)->getTimeout())->toBe(0.25);
    });
});

describe('RedisMutexOptions', function () {
    it('accepts a Duration and stores float seconds', function () {
        $options = (new RedisMutexOptions())
            ->withLockRenewInterval(Duration::fromMilliseconds(500))
            ->withLockTimeout(Duration::fromSeconds(4));

        expect($options->getLockRenewInterval())->toBe(0.5)
            ->and($options->getLockTimeout())->toBe(4.0);
    });

    it('keeps float seconds unchanged', function () {
        $options = (new RedisMutexOptions())->withLockRenewInterval(0.75)->withLockTimeout(2.5);

        expect($options->getLockRenewInterval())->toBe(0.75)
            ->and($options->getLockTimeout())->toBe(2.5);
    });
});

describe('RetryPolicy', function () {
    it('accepts Durations and exposes float seconds', function () {
        $policy = new RetryPolicy(
            retryIntervalSeconds: Duration::fromMilliseconds(100),
            backoffBase: Duration::fromMilliseconds(50),
            backoffCap: Duration::fromSeconds(2),
        );

        expect($policy->retryIntervalSeconds)->toBe(0.1)
            ->and($policy->backoffBase)->toBe(0.05)
            ->and($policy->backoffCap)->toBe(2.0);
    });

    it('keeps floats and null unchanged', function () {
        $policy = new RetryPolicy(retryIntervalSeconds: 0.2, backoffBase: null, backoffCap: 1.0);

        expect($policy->retryIntervalSeconds)->toBe(0.2)
            ->and($policy->backoffBase)->toBeNull()
            ->and($policy->backoffCap)->toBe(1.0);
    });
});

describe('ReconnectingRedisLink', function () {
    it('times a command out after a Duration like after the same float', function (Duration|float $readTimeout) {
        // Every connection accepts commands and never answers, until it is closed.
        $connector = new class implements RedisConnector {
            public function connect(?Cancellation $cancellation = null): RedisConnection
            {
                return new class implements RedisConnection {
                    private bool $closed = false;

                    private ?DeferredFuture $waiter = null;

                    public function receive(): ?RedisResponse
                    {
                        $this->waiter = new DeferredFuture();
                        $keepAlive = Fledge\Loop::repeat(0.01, static fn () => null);

                        try {
                            return $this->waiter->getFuture()->await();
                        } finally {
                            Fledge\Loop::cancel($keepAlive);
                        }
                    }

                    public function send(string ...$args): void
                    {
                    }

                    public function getName(): string
                    {
                        return 'silent';
                    }

                    public function reference(): void
                    {
                    }

                    public function unreference(): void
                    {
                    }

                    public function close(): void
                    {
                        $this->closed = true;

                        if ($this->waiter !== null && ! $this->waiter->isComplete()) {
                            $this->waiter->error(new RedisException('silent connection closed'));
                        }
                    }

                    public function isClosed(): bool
                    {
                        return $this->closed;
                    }

                    public function onClose(Closure $onClose): void
                    {
                    }
                };
            }
        };

        $link = new ReconnectingRedisLink($connector, $readTimeout, new BackoffStrategy('constant', 0.01));

        expect(fn () => $link->execute('GET', ['key']))
            ->toThrow(RedisTimeoutException::class, 'no response to GET within 0.050 seconds');
    })->with([
        'Duration' => fn () => Duration::fromMilliseconds(50),
        'float' => 0.05,
    ]);
});
