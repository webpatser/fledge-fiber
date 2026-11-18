<?php

use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\RedisSubscription;
use Fledge\Async\TimeoutCancellation;
use PHPUnit\Framework\Assert;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

/*
 * The subscriber must not hold or open a connection once the last
 * subscription is gone: not after a plain unsubscribe, not when the
 * unsubscribe lands while the loop is still connecting, and not when it
 * lands while the loop backs off after a dropped connection.
 */

function subscriberItParameters(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379),
    ];
}

/**
 * Every wait on the server (a subscription becoming active, a client count
 * settling) is bounded by this many seconds: longer than the 5 s default
 * connect timeout of the connections it waits for, so a slow connect fails
 * the wait with a message instead of racing it. Waits return as soon as the
 * condition holds, so the bound costs nothing in a passing run.
 */
const SUBSCRIBER_IT_WAIT = 10.0;

/**
 * Subscriptions and observers of the running test. afterEach unsubscribes and
 * disconnects them even when an assertion failed, so no subscriber loop or
 * connection of one test runs on into the next test of the same process.
 */
final class SubscriberItResources
{
    /** @var list<RedisSubscription|RedisClient> */
    public static array $tracked = [];
}

/**
 * @template T of RedisSubscription|RedisClient
 *
 * @param  T  $resource
 * @return T
 */
function subscriberItTrack(RedisSubscription|RedisClient $resource): RedisSubscription|RedisClient
{
    SubscriberItResources::$tracked[] = $resource;

    return $resource;
}

function subscriberItSubscriber(string $name, ?BackoffStrategy $backoff = null): RedisSubscriber
{
    return new RedisSubscriber(
        createRedisConnector(RedisConfig::fromParameters(subscriberItParameters() + ['name' => $name])),
        $backoff,
    );
}

function subscriberItSubscribe(RedisSubscriber $subscriber, string $channel): RedisSubscription
{
    return subscriberItTrack($subscriber->subscribe($channel));
}

function subscriberItObserver(): RedisClient
{
    return subscriberItTrack(createRedisClient(RedisConfig::fromParameters(subscriberItParameters())));
}

function subscriberItClients(RedisClient $observer, string $name): int
{
    return substr_count((string) $observer->execute('client', 'LIST'), "name={$name} ");
}

/**
 * Polls CLIENT LIST until the count of clients named $name equals $expected
 * (at most $timeout seconds). Returns whether it got there.
 */
function subscriberItAwaitClients(RedisClient $observer, string $name, int $expected, float $timeout = SUBSCRIBER_IT_WAIT): bool
{
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        if (subscriberItClients($observer, $name) === $expected) {
            return true;
        }

        delay(0.01);
    }

    return false;
}

/**
 * Highest count of clients named $name seen while polling CLIENT LIST for
 * $window seconds, so a connection that appears and lingers is caught.
 */
function subscriberItMaxClients(RedisClient $observer, string $name, float $window): int
{
    $deadline = microtime(true) + $window;
    $max = 0;

    while (microtime(true) < $deadline) {
        $max = max($max, subscriberItClients($observer, $name));
        delay(0.01);
    }

    return max($max, subscriberItClients($observer, $name));
}

/**
 * Publishes until the subscription receives a message, proving the SUBSCRIBE
 * is active on the server. One deadline of SUBSCRIBER_IT_WAIT bounds both the
 * wait for a subscriber on the channel and the wait for the message.
 */
function subscriberItAwaitActive(RedisClient $observer, RedisSubscription $subscription, string $channel): string
{
    $received = async(static function () use ($subscription): string {
        foreach ($subscription as $message) {
            return $message;
        }

        return '';
    });
    // Awaited below; ignore() only keeps a later failure of an abandoned
    // receive (after a failed wait) from surfacing in another test.
    $received->ignore();

    $started = microtime(true);
    $deadline = $started + SUBSCRIBER_IT_WAIT;

    while (($receivers = $observer->publish($channel, 'ping')) === 0 && microtime(true) < $deadline) {
        delay(0.01);
    }

    if ($receivers === 0) {
        try {
            $received->await(new TimeoutCancellation(0.01));
            $state = 'the subscription ended';
        } catch (CancelledException) {
            $state = 'the subscription is still pending';
        } catch (Throwable $e) {
            $state = 'the subscription failed: '.$e->getMessage();
        }

        Assert::fail(sprintf('No subscriber on %s after %.2f s; %s.', $channel, microtime(true) - $started, $state));
    }

    try {
        return $received->await(new TimeoutCancellation(max(0.5, $deadline - microtime(true))));
    } catch (CancelledException) {
        Assert::fail(sprintf('Subscription on %s received nothing %.2f s after its subscribe.', $channel, microtime(true) - $started));
    }
}

/**
 * Connects through the real Redis connector, except while $refuse is not 0:
 * then it connects through a real connector on 127.0.0.1:1, which loopback
 * refuses. $refuse counts down per refused connect; -1 refuses forever.
 */
final class SubscriberItRefusingConnector implements RedisConnector
{
    public int $refuse = 0;

    public int $refused = 0;

    private readonly RedisConnector $refusing;

    public function __construct(private readonly RedisConnector $real)
    {
        $this->refusing = createRedisConnector(RedisConfig::fromParameters(['host' => '127.0.0.1', 'port' => 1]));
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        if ($this->refuse === 0) {
            return $this->real->connect($cancellation);
        }

        if ($this->refuse > 0) {
            $this->refuse--;
        }

        $this->refused++;

        return $this->refusing->connect($cancellation);
    }
}

function subscriberItRefusingConnector(string $name): SubscriberItRefusingConnector
{
    return new SubscriberItRefusingConnector(
        createRedisConnector(RedisConfig::fromParameters(subscriberItParameters() + ['name' => $name])),
    );
}

/**
 * Drops the subscriber connection named $name from the server side. Names are
 * unique per test, so exactly one client carries it; the kill goes by its id.
 */
function subscriberItKill(RedisClient $observer, string $name): void
{
    preg_match_all('/^id=(\d+) .*name='.preg_quote($name, '/').' /m', (string) $observer->execute('client', 'LIST'), $matches);
    expect($matches[1])->toHaveCount(1);
    expect($observer->execute('client', 'KILL', 'ID', $matches[1][0]))->toBe(1);
}

/**
 * Iterates the subscription until it fails (at most $timeout seconds).
 * Returns the error and the seconds it took.
 *
 * @return array{0: ?Throwable, 1: float}
 */
function subscriberItAwaitFailure(RedisSubscription $subscription, float $timeout): array
{
    $started = hrtime(true);
    $error = null;

    try {
        async(static function () use ($subscription): void {
            foreach ($subscription as $message) {
                // Drain until the subscription fails.
            }
        })->await(new TimeoutCancellation($timeout));
    } catch (CancelledException) {
        Assert::fail(sprintf('Subscription did not fail within %.1f s.', $timeout));
    } catch (Throwable $e) {
        $error = $e;
    }

    return [$error, (hrtime(true) - $started) / 1e9];
}

afterEach(function () {
    $tracked = SubscriberItResources::$tracked;
    SubscriberItResources::$tracked = [];

    foreach ($tracked as $resource) {
        try {
            if ($resource instanceof RedisSubscription) {
                $resource->unsubscribe();
            } else {
                $resource->closeConnection();
            }
        } catch (Throwable) {
            // Cleanup only: the test already reported what went wrong.
        }
    }
});

uses()->beforeEach(function () {
    $parameters = subscriberItParameters();
    $sock = @fsockopen($parameters['host'], $parameters['port'], $errno, $errstr, 1);

    if (! $sock) {
        $this->markTestSkipped('Redis not available on port '.$parameters['port']);
    }

    fclose($sock);
});

it('opens no new connection after the last unsubscribe', function () {
    $name = 'fledge_sub_plain_'.uniqid();
    $channel = $name.'_ch';
    $observer = subscriberItObserver();
    $subscriber = subscriberItSubscriber($name);

    $subscription = subscriberItSubscribe($subscriber, $channel);

    expect(subscriberItAwaitActive($observer, $subscription, $channel))->toBe('ping')
        ->and(subscriberItClients($observer, $name))->toBe(1);

    $subscription->unsubscribe();
    unset($subscription);

    expect(subscriberItAwaitClients($observer, $name, 0))->toBeTrue()
        ->and(subscriberItMaxClients($observer, $name, 0.5))->toBe(0);

    unset($subscriber);
    $observer->quit();
});

it('keeps no connection when the last unsubscribe lands while connecting', function () {
    $name = 'fledge_sub_connecting_'.uniqid();
    $observer = subscriberItObserver();
    $subscriber = subscriberItSubscriber($name);

    // The loop starts connecting first; the unsubscribe runs while the
    // connect is in flight, so there is no connection to close yet.
    $subscription = subscriberItSubscribe($subscriber, $name.'_ch');
    $subscription->unsubscribe();
    unset($subscription);

    expect(subscriberItMaxClients($observer, $name, 0.5))->toBeLessThanOrEqual(1)
        ->and(subscriberItAwaitClients($observer, $name, 0))->toBeTrue()
        ->and(subscriberItMaxClients($observer, $name, 0.3))->toBe(0);

    // A new subscribe still works afterwards.
    $again = subscriberItSubscribe($subscriber, $name.'_ch');

    expect(subscriberItAwaitActive($observer, $again, $name.'_ch'))->toBe('ping');

    $again->unsubscribe();
    unset($again);

    expect(subscriberItAwaitClients($observer, $name, 0))->toBeTrue();

    unset($subscriber);
    $observer->quit();
});

it('does not reconnect when the last unsubscribe lands during the reconnect backoff', function () {
    $name = 'fledge_sub_backoff_'.uniqid();
    $channel = $name.'_ch';
    $observer = subscriberItObserver();
    $subscriber = subscriberItSubscriber($name, new BackoffStrategy('constant', 0.3, 0.3));

    $subscription = subscriberItSubscribe($subscriber, $channel);

    expect(subscriberItAwaitActive($observer, $subscription, $channel))->toBe('ping');

    // Drop the subscriber connection from the server side: the loop starts
    // its 0.3 s backoff. Unsubscribe the last subscription inside it.
    subscriberItKill($observer, $name);

    expect(subscriberItAwaitClients($observer, $name, 0))->toBeTrue();

    $subscription->unsubscribe();
    unset($subscription);

    expect(subscriberItMaxClients($observer, $name, 0.8))->toBe(0);

    unset($subscriber);
    $observer->quit();
});

/*
 * A Redis restart drops the subscriber connection and refuses connects for a
 * moment. Once a connection was established, a refused reconnect is one more
 * failure in the backoff loop, up to a bounded window; the first connect
 * still fails at once.
 */

it('keeps the subscription across refused reconnects after a drop', function () {
    $name = 'fledge_sub_refused_'.uniqid();
    $channel = $name.'_ch';
    $observer = subscriberItObserver();
    $connector = subscriberItRefusingConnector($name);
    $subscriber = new RedisSubscriber($connector, new BackoffStrategy('constant', 0.1, 0.1));

    $subscription = subscriberItSubscribe($subscriber, $channel);

    expect(subscriberItAwaitActive($observer, $subscription, $channel))->toBe('ping');

    // The next two reconnects are refused, the third reaches Redis again.
    $connector->refuse = 2;
    subscriberItKill($observer, $name);

    expect(subscriberItAwaitActive($observer, $subscription, $channel))->toBe('ping')
        ->and($connector->refused)->toBe(2)
        ->and(subscriberItClients($observer, $name))->toBe(1);

    $subscription->unsubscribe();
    unset($subscription);

    expect(subscriberItAwaitClients($observer, $name, 0))->toBeTrue();

    unset($subscriber);
    $observer->quit();
});

it('fails the subscriptions once refused reconnects exhaust the window', function () {
    $name = 'fledge_sub_refused_window_'.uniqid();
    $channel = $name.'_ch';
    $observer = subscriberItObserver();
    $connector = subscriberItRefusingConnector($name);
    $subscriber = new RedisSubscriber($connector, new BackoffStrategy('constant', 0.1, 0.1));

    $subscription = subscriberItSubscribe($subscriber, $channel);

    expect(subscriberItAwaitActive($observer, $subscription, $channel))->toBe('ping');

    $connector->refuse = -1;
    subscriberItKill($observer, $name);

    [$error, $elapsed] = subscriberItAwaitFailure($subscription, 12.0);

    // About 6 s of refused reconnects (what the socket-level retry of 2 s
    // plus 4 s used to give), then the queues fail with the connect error.
    expect($error)->toBeInstanceOf(RedisConnectionException::class)
        ->and($error->getMessage())->toBe('Failed to connect to redis instance (tcp://127.0.0.1:1)')
        ->and($elapsed)->toBeGreaterThanOrEqual(5.9)
        ->and($elapsed)->toBeLessThan(8.0)
        ->and($connector->refused)->toBeGreaterThan(2);

    unset($subscription, $subscriber);
    $observer->quit();
});

it('does not storm refused reconnects when the backoff returns zero', function () {
    $name = 'fledge_sub_refused_zero_'.uniqid();
    $channel = $name.'_ch';
    $observer = subscriberItObserver();
    $connector = subscriberItRefusingConnector($name);
    $subscriber = new RedisSubscriber($connector, new BackoffStrategy('constant', 0.0, 0.0));

    $subscription = subscriberItSubscribe($subscriber, $channel);

    expect(subscriberItAwaitActive($observer, $subscription, $channel))->toBe('ping');

    $connector->refuse = -1;
    subscriberItKill($observer, $name);

    [$error, $elapsed] = subscriberItAwaitFailure($subscription, 12.0);

    // A 0.05 s floor after a refusal allows about 6.0 / 0.05 = 120 attempts
    // in the window; without it the loop connects as fast as it can.
    expect($error)->toBeInstanceOf(RedisConnectionException::class)
        ->and($elapsed)->toBeGreaterThanOrEqual(5.9)
        ->and($connector->refused)->toBeLessThanOrEqual(130);

    unset($subscription, $subscriber);
    $observer->quit();
});

it('fails at once when the first connect is refused', function () {
    $connector = subscriberItRefusingConnector('fledge_sub_refused_first_'.uniqid());
    $connector->refuse = -1;
    $subscriber = new RedisSubscriber($connector, new BackoffStrategy('constant', 0.1, 0.1));

    $subscription = subscriberItSubscribe($subscriber, 'fledge_sub_refused_first_ch');

    [$error, $elapsed] = subscriberItAwaitFailure($subscription, 5.0);

    expect($error)->toBeInstanceOf(RedisConnectionException::class)
        ->and($error->getMessage())->toBe('Failed to connect to redis instance (tcp://127.0.0.1:1)')
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($connector->refused)->toBe(1);

    unset($subscription, $subscriber);
});
