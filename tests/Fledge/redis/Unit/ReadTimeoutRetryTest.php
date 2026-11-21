<?php

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnection;
use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Connection\RedisInFlightCommandException;
use Fledge\Async\Redis\Connection\RedisTimeoutException;
use Fledge\Async\Redis\Connection\RetryableCommands;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisException;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * Serves scripted receive results (RedisResponse instances or Throwables),
 * then blocks until the connection is closed.
 */
final class ScriptedRetryConnection implements RedisConnection
{
    /** @var list<list<string>> */
    public array $sent = [];

    public bool $closed = false;

    private ?DeferredFuture $waiter = null;

    /**
     * @param  list<RedisResponse|Throwable>  $receives
     */
    public function __construct(private array $receives = [])
    {
    }

    public function receive(): ?RedisResponse
    {
        if ($this->receives !== []) {
            $next = array_shift($this->receives);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        $this->waiter = new DeferredFuture();

        // Keep a referenced watcher alive while blocked: a real connection
        // holds a stream watcher, without one the loop reports a deadlock.
        $keepAlive = Fledge\Loop::repeat(0.01, static fn () => null);

        try {
            return $this->waiter->getFuture()->await();
        } finally {
            Fledge\Loop::cancel($keepAlive);
        }
    }

    public function send(string ...$args): void
    {
        $this->sent[] = $args;
    }

    public function getName(): string
    {
        return 'scripted';
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
            $this->waiter->error(new RedisException('scripted connection closed'));
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

final class ScriptedRetryConnector implements RedisConnector
{
    public int $connects = 0;

    /**
     * @param  list<RedisConnection>  $connections
     */
    public function __construct(private array $connections)
    {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $this->connects++;

        if ($this->connections === []) {
            throw new RedisConnectionException('no more scripted connections');
        }

        return array_shift($this->connections);
    }
}

final class AlwaysFailingConnector implements RedisConnector
{
    public int $connects = 0;

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $this->connects++;

        throw new RedisConnectionException('scripted connect failure');
    }
}

it('resends a retryable command once after a reconnect', function () {
    $conn1 = new ScriptedRetryConnection([new RedisException('went away')]);
    $conn2 = new ScriptedRetryConnection([new RedisValue('value')]);
    $connector = new ScriptedRetryConnector([$conn1, $conn2]);

    $link = new ReconnectingRedisLink($connector, backoff: new BackoffStrategy('constant', 0.01));

    expect($link->execute('GET', ['key'])->unwrap())->toBe('value')
        ->and($conn1->sent)->toBe([['GET', 'key']])
        ->and($conn2->sent)->toBe([['GET', 'key']])
        ->and($connector->connects)->toBe(2);
});

it('fails a non-retryable in-flight command instead of resending it', function () {
    $conn1 = new ScriptedRetryConnection([new RedisException('went away')]);
    $conn2 = new ScriptedRetryConnection();

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    try {
        $link->execute('INCR', ['counter']);
        $this->fail('Expected RedisInFlightCommandException.');
    } catch (RedisInFlightCommandException $e) {
        expect($e->getMessage())->toContain('may have executed on the server')
            ->and($e->getMessage())->toContain('not resent');
    }

    delay(0.05);

    expect($conn2->sent)->toBe([]);
});

it('resends retryable and fails non-retryable commands from the same queue', function () {
    $conn1 = new ScriptedRetryConnection();
    $conn2 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    $get = async(fn () => $link->execute('GET', ['key']));
    $incr = async(fn () => $link->execute('INCR', ['counter']));

    delay(0.05);
    expect($conn1->sent)->toBe([['GET', 'key'], ['INCR', 'counter']]);

    $conn1->close();

    expect($get->await()->unwrap())->toBe('value')
        ->and(fn () => $incr->await())->toThrow(RedisInFlightCommandException::class)
        ->and($conn2->sent)->toBe([['GET', 'key']]);
});

it('resends an in-flight DEL after a lost connection', function () {
    $conn1 = new ScriptedRetryConnection();
    $conn2 = new ScriptedRetryConnection([new RedisValue(1)]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    $del = async(fn () => $link->execute('DEL', ['key']));

    delay(0.05);
    expect($conn1->sent)->toBe([['DEL', 'key']]);

    $conn1->close();

    expect($del->await()->unwrap())->toBe(1)
        ->and($conn2->sent)->toBe([['DEL', 'key']]);
});

it('does not resend a retryable command that was in flight inside MULTI', function () {
    $conn1 = new ScriptedRetryConnection([new RedisValue('OK')]);
    $conn2 = new ScriptedRetryConnection();

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    expect($link->execute('MULTI', [])->unwrap())->toBe('OK');

    $set = async(fn () => $link->execute('SET', ['key', 'value']));

    delay(0.05);
    expect($conn1->sent)->toBe([['MULTI'], ['SET', 'key', 'value']]);

    $conn1->close();

    try {
        $set->await();
        $this->fail('Expected RedisInFlightCommandException.');
    } catch (RedisInFlightCommandException $e) {
        expect($e->getMessage())->toContain('inside MULTI')
            ->and($e->getMessage())->toContain('not resent');
    }

    delay(0.05);

    expect($conn2->sent)->toBe([]);
});

it('fails pipelined MULTI and queued commands sent before any reply', function () {
    $conn1 = new ScriptedRetryConnection();
    $conn2 = new ScriptedRetryConnection();

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    $multi = async(fn () => $link->execute('MULTI', []));
    $get = async(fn () => $link->execute('GET', ['key']));
    $exec = async(fn () => $link->execute('EXEC', []));

    delay(0.05);
    expect($conn1->sent)->toBe([['MULTI'], ['GET', 'key'], ['EXEC']]);

    $conn1->close();

    expect(fn () => $multi->await())->toThrow(RedisInFlightCommandException::class)
        ->and(fn () => $get->await())->toThrow(RedisInFlightCommandException::class)
        ->and(fn () => $exec->await())->toThrow(RedisInFlightCommandException::class);

    delay(0.05);

    expect($conn2->sent)->toBe([]);
});

it('fails a command queued after the connection carrying an open MULTI was lost', function () {
    $conn1 = new ScriptedRetryConnection([new RedisValue('OK'), new RedisException('went away')]);
    $conn2 = new ScriptedRetryConnection();

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    expect($link->execute('MULTI', [])->unwrap())->toBe('OK');

    // conn1 is gone with nothing pending: the next command reconnects.
    delay(0.05);

    expect(fn () => $link->execute('SET', ['key', 'value']))->toThrow(RedisInFlightCommandException::class);

    delay(0.05);

    expect($conn2->sent)->toBe([]);
});

it('still resends a retryable in-flight command outside a transaction', function () {
    $conn1 = new ScriptedRetryConnection();
    $conn2 = new ScriptedRetryConnection([new RedisValue('OK')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    $set = async(fn () => $link->execute('SET', ['key', 'value']));

    delay(0.05);
    expect($conn1->sent)->toBe([['SET', 'key', 'value']]);

    $conn1->close();

    expect($set->await()->unwrap())->toBe('OK')
        ->and($conn2->sent)->toBe([['SET', 'key', 'value']]);
});

it('resends retryable commands again once EXEC closed the transaction', function () {
    $conn1 = new ScriptedRetryConnection([
        new RedisValue('OK'),
        new RedisValue('QUEUED'),
        new RedisValue([1]),
    ]);
    $conn2 = new ScriptedRetryConnection([new RedisValue(1)]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    // Pipelined: the scripted replies are served as soon as they are read,
    // so all three commands must be on the wire first.
    $multi = async(fn () => $link->execute('MULTI', []));
    $incr = async(fn () => $link->execute('INCR', ['counter']));
    $exec = async(fn () => $link->execute('EXEC', []));

    expect($multi->await()->unwrap())->toBe('OK')
        ->and($incr->await()->unwrap())->toBe('QUEUED')
        ->and($exec->await()->unwrap())->toBe([1]);

    $del = async(fn () => $link->execute('DEL', ['key']));

    delay(0.05);
    expect($conn1->sent)->toBe([['MULTI'], ['INCR', 'counter'], ['EXEC'], ['DEL', 'key']]);

    $conn1->close();

    expect($del->await()->unwrap())->toBe(1)
        ->and($conn2->sent)->toBe([['DEL', 'key']]);
});

it('starts clean after a reconnect that dropped an open transaction', function () {
    $conn1 = new ScriptedRetryConnection([new RedisValue('OK')]);
    $conn2 = new ScriptedRetryConnection();
    $conn3 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2, $conn3]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    expect($link->execute('MULTI', [])->unwrap())->toBe('OK');

    $inside = async(fn () => $link->execute('GET', ['key']));

    delay(0.05);
    $conn1->close();

    expect(fn () => $inside->await())->toThrow(RedisInFlightCommandException::class);

    delay(0.05);

    // No MULTI on conn2: a retryable command is resent as outside any transaction.
    $outside = async(fn () => $link->execute('GET', ['key']));

    delay(0.05);
    expect($conn2->sent)->toBe([['GET', 'key']]);

    $conn2->close();

    expect($outside->await()->unwrap())->toBe('value')
        ->and($conn3->sent)->toBe([['GET', 'key']]);
});

it('does not tie later commands to a MULTI that hit the read timeout', function () {
    $conn1 = new ScriptedRetryConnection();
    $conn2 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        readTimeout: 0.05,
        backoff: new BackoffStrategy('constant', 0.01),
    );

    expect(fn () => $link->execute('MULTI', []))->toThrow(RedisTimeoutException::class);

    expect($link->execute('GET', ['key'])->unwrap())->toBe('value')
        ->and($conn2->sent)->toBe([['GET', 'key']]);
});

it('resends a retryable command normally after MULTI was rejected', function () {
    $conn1 = new ScriptedRetryConnection([new RedisError('ERR MULTI calls can not be nested')]);
    $conn2 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    expect($link->execute('MULTI', []))->toBeInstanceOf(RedisError::class);

    $get = async(fn () => $link->execute('GET', ['key']));

    delay(0.05);
    expect($conn1->sent)->toBe([['MULTI'], ['GET', 'key']]);

    $conn1->close();

    expect($get->await()->unwrap())->toBe('value')
        ->and($conn2->sent)->toBe([['GET', 'key']]);
});

it('resends a retryable command once DISCARD closed the transaction', function () {
    $conn1 = new ScriptedRetryConnection([new RedisValue('OK'), new RedisValue('OK')]);
    $conn2 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    $multi = async(fn () => $link->execute('MULTI', []));
    $discard = async(fn () => $link->execute('DISCARD', []));

    expect($multi->await()->unwrap())->toBe('OK')
        ->and($discard->await()->unwrap())->toBe('OK');

    $get = async(fn () => $link->execute('GET', ['key']));

    delay(0.05);
    expect($conn1->sent)->toBe([['MULTI'], ['DISCARD'], ['GET', 'key']]);

    $conn1->close();

    expect($get->await()->unwrap())->toBe('value')
        ->and($conn2->sent)->toBe([['GET', 'key']]);
});

it('resends a retryable command once RESET closed the transaction', function () {
    $conn1 = new ScriptedRetryConnection([new RedisValue('OK'), new RedisValue('RESET')]);
    $conn2 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    $multi = async(fn () => $link->execute('MULTI', []));
    $reset = async(fn () => $link->execute('reset', []));

    expect($multi->await()->unwrap())->toBe('OK')
        ->and($reset->await()->unwrap())->toBe('RESET');

    $get = async(fn () => $link->execute('GET', ['key']));

    delay(0.05);
    expect($conn1->sent)->toBe([['MULTI'], ['reset'], ['GET', 'key']]);

    $conn1->close();

    expect($get->await()->unwrap())->toBe('value')
        ->and($conn2->sent)->toBe([['GET', 'key']]);
});

it('fails only the first command after a dropped transaction', function () {
    $conn1 = new ScriptedRetryConnection([new RedisValue('OK'), new RedisException('went away')]);
    $conn2 = new ScriptedRetryConnection();
    $conn3 = new ScriptedRetryConnection([new RedisValue('value')]);

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2, $conn3]),
        backoff: new BackoffStrategy('constant', 0.01),
    );

    expect($link->execute('MULTI', [])->unwrap())->toBe('OK');

    // conn1 is gone with nothing pending: the reconnect drops the transaction.
    delay(0.05);

    expect(fn () => $link->execute('SET', ['key', 'value']))->toThrow(RedisInFlightCommandException::class);

    // The rule fired once: the next command is sent, not failed.
    $get = async(fn () => $link->execute('GET', ['key']));

    delay(0.05);
    expect($conn2->sent)->toBe([['GET', 'key']]);

    $conn2->close();

    expect($get->await()->unwrap())->toBe('value')
        ->and($conn3->sent)->toBe([['GET', 'key']]);
});

it('closes the connection and throws a timeout exception when the read timeout fires', function () {
    $conn1 = new ScriptedRetryConnection();
    $conn2 = new ScriptedRetryConnection();

    $link = new ReconnectingRedisLink(
        new ScriptedRetryConnector([$conn1, $conn2]),
        readTimeout: 0.05,
        backoff: new BackoffStrategy('constant', 0.01),
    );

    try {
        $link->execute('GET', ['key']);
        $this->fail('Expected RedisTimeoutException.');
    } catch (RedisTimeoutException $e) {
        expect($e)->toBeInstanceOf(RedisException::class)
            ->and($e->getMessage())->toContain('read error on connection');
    }

    expect($conn1->closed)->toBeTrue();

    // The settled entry is skipped on reconnect: nothing is resent.
    delay(0.1);
    expect($conn2->sent)->toBe([]);
});

it('drains the queue with an error once max reconnect attempts are exhausted', function () {
    $connector = new AlwaysFailingConnector();

    $link = new ReconnectingRedisLink(
        $connector,
        backoff: new BackoffStrategy('constant', 0.01),
        maxReconnectAttempts: 2,
    );

    expect(fn () => $link->execute('GET', ['key']))->toThrow(RedisConnectionException::class)
        ->and($connector->connects)->toBe(3);
});

it('fails fast on a connect error when no max reconnect attempts are configured', function () {
    $connector = new AlwaysFailingConnector();

    $link = new ReconnectingRedisLink($connector);

    expect(fn () => $link->execute('GET', ['key']))->toThrow(RedisConnectionException::class)
        ->and($connector->connects)->toBe(1);
});

it('classifies retryable commands like the upstream whitelist', function () {
    expect(RetryableCommands::isRetryable('GET', ['key']))->toBeTrue()
        ->and(RetryableCommands::isRetryable('get', ['key']))->toBeTrue()
        ->and(RetryableCommands::isRetryable('mget', ['a', 'b']))->toBeTrue()
        ->and(RetryableCommands::isRetryable('INCR', ['counter']))->toBeFalse()
        ->and(RetryableCommands::isRetryable('lpush', ['list', 'x']))->toBeFalse()
        ->and(RetryableCommands::isRetryable('set', ['key', 'value']))->toBeTrue()
        ->and(RetryableCommands::isRetryable('set', ['key', 'value', 'EX', 10]))->toBeFalse();
});

it('treats DEL as retryable like upstream v13.43.0', function () {
    expect(RetryableCommands::isRetryable('DEL', ['key']))->toBeTrue()
        ->and(RetryableCommands::isRetryable('del', ['a', 'b']))->toBeTrue()
        ->and(RetryableCommands::isRetryable('unlink', ['key']))->toBeFalse();
});
