<?php

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\RedisSubscription;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * The subscriber reconnect loop used to reconnect immediately on every
 * failure: a server that dropped the connection right after SUBSCRIBE, or a
 * rejected SUBSCRIBE (-NOPERM, unwrapped into a QueryException), turned it
 * into a tight connect/subscribe loop. Reconnects now back off, and an error
 * reply fails only the subscription it answers, like phpredis throwing to the
 * subscribe() caller.
 */

/**
 * Answers (p)subscribe and (p)unsubscribe like a server would, rejects the
 * channels in $denied with -NOPERM, then plays $afterSubscribe (responses, or
 * false for EOF). Blocks in receive() until something is delivered.
 */
final class SubscriberScriptedConnection implements RedisConnection
{
    /** @var list<list<string>> */
    public array $sent = [];

    public bool $closed = false;

    /** @var list<RedisResponse|false> */
    private array $inbox = [];

    private ?DeferredFuture $waiter = null;

    /**
     * @param  list<string>  $denied
     * @param  list<RedisResponse|false>  $afterSubscribe
     */
    public function __construct(
        private readonly array $denied = [],
        private array $afterSubscribe = [],
        private readonly bool $confirm = true,
    ) {
    }

    public function deliver(RedisResponse|false $response): void
    {
        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            $waiter->complete($response === false ? null : $response);

            return;
        }

        $this->inbox[] = $response;
    }

    public function receive(): ?RedisResponse
    {
        if ($this->inbox !== []) {
            $next = array_shift($this->inbox);

            return $next === false ? null : $next;
        }

        $this->waiter = new DeferredFuture();

        // A real connection holds a stream watcher while blocked.
        $keepAlive = Revolt\EventLoop::repeat(0.01, static fn () => null);

        try {
            return $this->waiter->getFuture()->await();
        } finally {
            Revolt\EventLoop::cancel($keepAlive);
        }
    }

    public function send(string ...$args): void
    {
        $this->sent[] = $args;

        [$command, $name] = $args;

        if (in_array($command, ['subscribe', 'psubscribe'], true) && in_array($name, $this->denied, true)) {
            $this->deliver(new RedisError('NOPERM this user has no permissions to access one of the channels used as arguments'));

            return;
        }

        if ($this->confirm) {
            $this->deliver(new RedisValue([$command, $name, 1]));
        }

        if (in_array($command, ['subscribe', 'psubscribe'], true)) {
            foreach ($this->afterSubscribe as $response) {
                $this->deliver($response);
            }

            $this->afterSubscribe = [];
        }
    }

    public function getName(): string
    {
        return 'subscriber-scripted';
    }

    public function reference(): void
    {
    }

    public function unreference(): void
    {
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->deliver(false);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
    }
}

/**
 * Hands out connections from $factory with a small connect latency, and
 * refuses after $limit connects so a reconnect storm cannot hang the run.
 */
final class SubscriberScriptedConnector implements RedisConnector
{
    public int $connects = 0;

    /** @var list<SubscriberScriptedConnection> */
    public array $connections = [];

    public bool $refuse = false;

    public function __construct(
        private readonly Closure $factory,
        private readonly int $limit = 50,
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        delay(0.001);

        if ($this->refuse || $this->connects >= $this->limit) {
            throw new RedisConnectionException('scripted connector refused');
        }

        $this->connects++;

        return $this->connections[] = ($this->factory)($this->connects);
    }
}

/**
 * Records the consecutive-failure count of every delay the subscriber asks
 * for, and keeps the delays tiny so tests assert on requests, not on time.
 */
readonly class SubscriberRecordingBackoff extends BackoffStrategy
{
    public function __construct(public ArrayObject $attempts = new ArrayObject())
    {
        parent::__construct('constant', 0.001, 0.001);
    }

    public function delay(int $attempt, ?float $previous = null): float
    {
        $this->attempts[] = $attempt;

        return parent::delay($attempt, $previous);
    }
}

function waitForSubscriberConnects(SubscriberScriptedConnector $connector, int $connects): void
{
    for ($i = 0; $i < 200 && $connector->connects < $connects; $i++) {
        delay(0.005);
    }
}

/**
 * @return list<string>
 */
function collectSubscriberMessages(RedisSubscription $subscription, string $until, float $timeout = 2.0): array
{
    return async(static function () use ($subscription, $until): array {
        $messages = [];

        foreach ($subscription as $message) {
            if ($message === $until) {
                break;
            }

            $messages[] = $message;
        }

        return $messages;
    })->await(new TimeoutCancellation($timeout));
}

function stopSubscriber(SubscriberScriptedConnector $connector, ?RedisSubscriber &$subscriber): void
{
    $connector->refuse = true;

    foreach ($connector->connections as $connection) {
        $connection->close();
    }

    $subscriber = null;

    // Let the loop observe the refusal and finish before the next test.
    delay(0.05);
}

it('backs off between reconnects when the server drops the connection before confirming', function () {
    $connector = new SubscriberScriptedConnector(
        static fn () => new SubscriberScriptedConnection(afterSubscribe: [false], confirm: false),
    );
    $subscriber = new RedisSubscriber($connector);

    $subscription = $subscriber->subscribe('ch');

    // Without backoff the loop reconnects every ~1ms (hitting the connector
    // limit of 50); with 0.1s, 0.2s, ... it fits a handful into this window.
    delay(0.35);

    expect($connector->connects)->toBeLessThanOrEqual(5)
        ->and($connector->connects)->toBeGreaterThanOrEqual(2);

    $subscription->unsubscribe();
    unset($subscription);
    stopSubscriber($connector, $subscriber);
});

it('delivers every message exactly once across a reconnect', function () {
    $connector = new SubscriberScriptedConnector(static fn (int $n) => new SubscriberScriptedConnection(
        afterSubscribe: $n === 1
            ? [new RedisValue(['message', 'ch', 'm1']), new RedisValue(['message', 'ch', 'm2']), false]
            : [new RedisValue(['message', 'ch', 'm3']), new RedisValue(['message', 'ch', 'm4']), new RedisValue(['message', 'ch', 'end'])],
    ));
    $subscriber = new RedisSubscriber($connector);

    $subscription = $subscriber->subscribe('ch');

    expect(collectSubscriberMessages($subscription, 'end'))->toBe(['m1', 'm2', 'm3', 'm4'])
        ->and($connector->connects)->toBe(2)
        ->and($connector->connections[1]->sent)->toBe([['subscribe', 'ch']]);

    $subscription->unsubscribe();
    unset($subscription);
    stopSubscriber($connector, $subscriber);
});

it('fails only the rejected channel subscription and keeps the connection', function () {
    $connector = new SubscriberScriptedConnector(
        static fn () => new SubscriberScriptedConnection(denied: ['secret']),
    );
    $subscriber = new RedisSubscriber($connector);

    $ok = $subscriber->subscribe('ok');
    $denied = $subscriber->subscribe('secret');

    $error = null;

    try {
        collectSubscriberMessages($denied, 'never');
    } catch (QueryException $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getMessage())->toStartWith('NOPERM');

    $connection = $connector->connections[0];
    $connection->deliver(new RedisValue(['message', 'ok', 'm1']));
    $connection->deliver(new RedisValue(['message', 'ok', 'm2']));
    $connection->deliver(new RedisValue(['message', 'ok', 'end']));

    expect(collectSubscriberMessages($ok, 'end'))->toBe(['m1', 'm2'])
        ->and($connector->connects)->toBe(1);

    // A later subscribe to the denied channel asks the server again.
    $retry = $subscriber->subscribe('secret');

    try {
        collectSubscriberMessages($retry, 'never');
        $this->fail('Expected the second subscribe to be rejected too.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toStartWith('NOPERM');
    }

    expect($connector->connects)->toBe(1);

    $ok->unsubscribe();
    unset($ok, $denied, $retry);
    stopSubscriber($connector, $subscriber);
});

it('fails only the rejected pattern subscription and keeps the connection', function () {
    $connector = new SubscriberScriptedConnector(
        static fn () => new SubscriberScriptedConnection(denied: ['secret.*']),
    );
    $subscriber = new RedisSubscriber($connector);

    $ok = $subscriber->subscribeToPattern('ok.*');
    $denied = $subscriber->subscribeToPattern('secret.*');

    $error = null;

    try {
        collectSubscriberMessages($denied, 'never');
    } catch (QueryException $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(QueryException::class);

    $connection = $connector->connections[0];
    $connection->deliver(new RedisValue(['pmessage', 'ok.*', 'ok.a', 'm1']));

    $received = async(static function () use ($ok): array {
        foreach ($ok as $message) {
            return $message;
        }

        return [];
    })->await(new TimeoutCancellation(2.0));

    expect($received)->toBe(['m1', 'ok.a'])
        ->and($connector->connects)->toBe(1);

    $ok->unsubscribe();
    unset($ok, $denied);
    stopSubscriber($connector, $subscriber);
});

it('grows the requested backoff while connections drop before any confirmation', function () {
    $backoff = new SubscriberRecordingBackoff();
    $connector = new SubscriberScriptedConnector(
        static fn () => new SubscriberScriptedConnection(afterSubscribe: [false], confirm: false),
        limit: 5,
    );
    $subscriber = new RedisSubscriber($connector, $backoff);

    $subscription = $subscriber->subscribe('ch');

    waitForSubscriberConnects($connector, 5);
    delay(0.05);

    // Five connects, then the connector refuses: a refused reconnect after
    // a drop is one more failure, so the requested attempts keep growing.
    expect($connector->connects)->toBe(5)
        ->and(array_slice($backoff->attempts->getArrayCopy(), 0, 6))->toBe([1, 2, 3, 4, 5, 6]);

    $subscription->unsubscribe();
    unset($subscription);
    stopSubscriber($connector, $subscriber);
});

it('does not grow the backoff when each connection confirmed its subscribe before dropping', function () {
    $backoff = new SubscriberRecordingBackoff();
    $connector = new SubscriberScriptedConnector(static fn (int $n) => new SubscriberScriptedConnection(
        afterSubscribe: $n < 3 ? [false] : [],
    ));
    $subscriber = new RedisSubscriber($connector, $backoff);

    $subscription = $subscriber->subscribe('ch');

    waitForSubscriberConnects($connector, 3);
    delay(0.02);

    // Each drop follows a confirmed subscribe, so every reconnect asks for
    // the first-attempt delay instead of 1, 2, 3, ...
    expect($connector->connects)->toBe(3)
        ->and($backoff->attempts->getArrayCopy())->toBe([1, 1])
        ->and($connector->connections[2]->sent)->toBe([['subscribe', 'ch']]);

    $subscription->unsubscribe();
    unset($subscription);
    stopSubscriber($connector, $subscriber);
});

it('connects at once for a new subscribe after the last unsubscribe closed the connection', function () {
    $backoff = new SubscriberRecordingBackoff();
    $connector = new SubscriberScriptedConnector(static fn () => new SubscriberScriptedConnection());
    $subscriber = new RedisSubscriber($connector, $backoff);

    $first = $subscriber->subscribe('ch');
    waitForSubscriberConnects($connector, 1);
    delay(0.01);

    $first->unsubscribe();
    unset($first);
    delay(0.02);

    expect($connector->connections[0]->closed)->toBeTrue()
        ->and($connector->connections[0]->sent)->toBe([['subscribe', 'ch'], ['unsubscribe', 'ch']]);

    $second = $subscriber->subscribe('ch');
    waitForSubscriberConnects($connector, 2);
    delay(0.01);

    $connector->connections[1]->deliver(new RedisValue(['message', 'ch', 'm1']));
    $connector->connections[1]->deliver(new RedisValue(['message', 'ch', 'end']));

    // The deliberate close is not a failure: no delay was requested.
    expect(collectSubscriberMessages($second, 'end'))->toBe(['m1'])
        ->and($connector->connects)->toBe(2)
        ->and($backoff->attempts->getArrayCopy())->toBe([])
        ->and($connector->connections[1]->sent)->toBe([['subscribe', 'ch']]);

    $second->unsubscribe();
    unset($second);
    stopSubscriber($connector, $subscriber);
});
