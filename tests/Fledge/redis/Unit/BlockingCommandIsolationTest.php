<?php

use Fledge\Async\DeferredFuture;
use Fledge\Async\Redis\Connection\RedisInFlightCommandException;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisException;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * One fake Redis connection: commands are answered strictly in the order
 * they were sent, like a real socket, so a blocking command holds back
 * every command queued behind it. Blocking commands wait for release().
 */
final class BlockingFakeLink implements RedisLink
{
    /** @var list<string> */
    public array $log = [];

    /** @var array<string, mixed> */
    public array $replies = [];

    public ?string $failWith = null;

    public ?string $errorReply = null;

    /** Fail this many next commands with a transient error. */
    public int $failTimes = 0;

    public bool $closed = false;

    private ?\Fledge\Async\Future $tail = null;

    private DeferredFuture $blocker;

    /**
     * @param  ArrayObject<int, string>  $events  shared across links: wire order of sends and replies
     */
    public function __construct(public string $name, public ArrayObject $events)
    {
        $this->blocker = new DeferredFuture;
    }

    public function release(): void
    {
        if (! $this->blocker->isComplete()) {
            $this->blocker->complete();
        }
    }

    /**
     * Drop the socket: a blocking command in flight fails like on a lost
     * connection (ReconnectingRedisLink::closeConnection()).
     */
    public function closeConnection(): void
    {
        $this->log[] = 'CLOSE';
        $this->closed = true;
        $this->release();
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);
        $entry = trim($command.' '.implode(' ', $parameters));
        $this->log[] = $entry;
        $this->events[] = "{$this->name} send {$entry}";

        $previous = $this->tail;
        $done = new DeferredFuture;
        $this->tail = $done->getFuture();

        try {
            $previous?->await();

            if ($this->failWith !== null) {
                throw new RedisException($this->failWith);
            }

            if ($this->failTimes > 0) {
                $this->failTimes--;

                throw new RedisException('redis went away');
            }

            if (str_starts_with($command, 'B') || str_contains(strtoupper($entry), ' BLOCK ')) {
                $this->blocker->getFuture()->await();
                // A real reply always arrives through the loop; awaiting a released blocker no longer yields.
                delay(0);

                if ($this->closed) {
                    throw new RedisInFlightCommandException("Redis connection lost while {$command} was in flight: the command may have executed on the server; not resent");
                }

                if ($this->errorReply !== null) {
                    return new RedisError($this->errorReply);
                }
            } else {
                delay(0.001);
            }

            $this->events[] = "{$this->name} done {$entry}";

            return new RedisValue(array_key_exists($command, $this->replies) ? $this->replies[$command] : 'OK');
        } finally {
            $done->complete();
        }
    }
}

/**
 * @return array{FledgeRedisConnection, BlockingFakeLink, list<BlockingFakeLink>}
 */
function blockingConnection(?ArrayObject &$events = null, bool $hold = false, string $prefix = '', array $config = []): array
{
    $events = new ArrayObject;
    $shared = new BlockingFakeLink('shared', $events);
    // Released up front so a blocking command that wrongly lands here fails
    // the log assertions instead of hanging the run.
    $shared->release();
    $dedicated = new ArrayObject;

    $connection = new FledgeRedisConnection(
        new RedisClient($shared),
        null,
        function () use ($events, $dedicated, $hold) {
            $link = new BlockingFakeLink('dedicated'.count($dedicated), $events);
            $link->replies = ['BLPOP' => ['q', 'job']];

            // Held links block until the test releases them, so concurrent
            // blocking calls really overlap.
            if (! $hold) {
                $link->release();
            }

            $dedicated[] = $link;

            return new RedisClient($link);
        },
        $config,
        $prefix,
    );

    return [$connection, $shared, $dedicated];
}

it('runs blpop on its own connection so other fibers are not queued behind it', function () {
    $events = new ArrayObject;
    $shared = new BlockingFakeLink('shared', $events);
    $shared->replies = ['GET' => 'value'];
    $dedicated = new BlockingFakeLink('dedicated', $events);
    $dedicated->replies = ['BLPOP' => ['q', 'job']];
    $connects = 0;

    $connection = new FledgeRedisConnection(new RedisClient($shared), null, function () use ($dedicated, &$connects) {
        $connects++;

        return new RedisClient($dedicated);
    });

    // Hang guard only: without it the old code would wait forever.
    EventLoop::delay(0.5, function () use ($shared, $dedicated): void {
        $shared->release();
        $dedicated->release();
    });

    $pop = async(fn () => $connection->blpop('q', 0));
    delay(0);

    $value = $connection->get('k');
    $popDoneWhenGetReturned = $pop->isComplete();

    $dedicated->release();

    expect($value)->toBe('value')
        ->and($popDoneWhenGetReturned)->toBeFalse()
        ->and($pop->await())->toBe(['q', 'job'])
        ->and($shared->log)->toBe(['GET k'])
        ->and($dedicated->log)->toBe(['BLPOP q 0'])
        ->and($connects)->toBe(1);
});

it('reuses an idle blocking connection and opens one per concurrent blocking call', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $connection->blpop('q', 0);
    $connection->blpop('q', 0);

    expect(count($dedicated))->toBe(1)
        ->and($dedicated[0]->log)->toBe(['BLPOP q 0', 'BLPOP q 0']);

    $a = async(fn () => $connection->blpop('q', 1));
    $b = async(fn () => $connection->blpop('q', 2));
    $a->await();
    $b->await();
    $connection->blpop('q', 3);

    expect(count($dedicated))->toBe(2)
        ->and(array_sum(array_map(fn ($link) => count($link->log), (array) $dedicated)))->toBe(5)
        ->and($shared->log)->toBe([]);
});

it('routes every blocking command off the shared connection', function (string $method, array $parameters) {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $connection->command($method, $parameters);

    expect($shared->log)->toBe([])
        ->and(count($dedicated))->toBe(1)
        ->and($dedicated[0]->log)->toHaveCount(1);
})->with([
    'brpop' => ['brpop', ['q', 0]],
    'brpoplpush' => ['brpoplpush', ['a', 'b', 0]],
    'blmove' => ['blmove', ['a', 'b', 'LEFT', 'RIGHT', 0]],
    'blmpop' => ['blmpop', [0, 1, 'a', 'LEFT']],
    'bzpopmin' => ['bzpopmin', ['z', 0]],
    'bzpopmax' => ['bzpopmax', ['z', 0]],
    'bzmpop' => ['bzmpop', [0, 1, 'z', 'MIN']],
    'xread block' => ['xread', ['COUNT', 1, 'BLOCK', 0, 'STREAMS', 's', '$']],
    'xreadgroup block' => ['xreadgroup', ['GROUP', 'g', 'c', 'block', 100, 'STREAMS', 's', '>']],
]);

it('keeps non-blocking stream reads on the shared connection', function (string $method, array $parameters) {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $connection->command($method, $parameters);

    expect($shared->log)->toHaveCount(1)
        ->and(count($dedicated))->toBe(0);
})->with([
    'xread' => ['xread', ['COUNT', 1, 'STREAMS', 's', '0']],
    'xread on a key named BLOCK' => ['xread', ['STREAMS', 'BLOCK', '0']],
    'xreadgroup with a group named BLOCK' => ['xreadgroup', ['GROUP', 'BLOCK', 'BLOCK', 'STREAMS', 's', '>']],
]);

it('keeps a blocking command inside the fiber\'s multi on the shared connection', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->release();

    $connection->multi();
    $connection->blpop('q', 0);
    $connection->exec();

    expect($shared->log)->toBe(['MULTI', 'BLPOP q 0', 'EXEC'])
        ->and(count($dedicated))->toBe(0);
});

it('selects the runtime database on the blocking connection', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $connection->select(3);
    $connection->blpop('q', 0);
    $connection->blpop('q', 0);
    $connection->select(4);
    $connection->blpop('q', 0);

    expect($shared->log)->toBe(['SELECT 3', 'SELECT 4'])
        ->and(count($dedicated))->toBe(2)
        ->and($dedicated[0]->log)->toBe(['SELECT 3', 'BLPOP q 0', 'BLPOP q 0', 'QUIT'])
        ->and($dedicated[1]->log)->toBe(['SELECT 4', 'BLPOP q 0']);
});

it('keeps at most the idle cap of blocking connections open after concurrent calls', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events, hold: true);

    $pops = [];

    for ($i = 0; $i < 10; $i++) {
        $pops[] = async(fn () => $connection->blpop('q', 0));
    }

    delay(0);

    foreach ($dedicated as $link) {
        $link->release();
    }

    foreach ($pops as $pop) {
        expect($pop->await())->toBe(['q', 'job']);
    }

    $open = array_filter((array) $dedicated, fn ($link) => ! in_array('QUIT', $link->log, true));

    expect(count($dedicated))->toBe(10)
        ->and($open)->toHaveCount(2);

    // The kept ones are reused, no new connection is opened.
    $connection->blpop('q', 0);

    expect(count($dedicated))->toBe(10);
});

it('selects the runtime database again on a rebuilt shared connection before the retry', function () {
    $events = new ArrayObject;
    $shared = new BlockingFakeLink('shared', $events);
    $shared->release();
    $connects = 0;

    $connection = new FledgeRedisConnection(new RedisClient($shared), null, function () use ($shared, &$connects) {
        $connects++;

        return new RedisClient($shared);
    });

    $connection->select(3);
    $shared->failTimes = 1;
    $connection->get('k');

    expect($connects)->toBe(1)
        ->and($shared->log)->toBe(['SELECT 3', 'GET k', 'SELECT 3', 'GET k']);
});

it('tracks a select executed inside the fiber\'s own multi', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->replies = ['EXEC' => ['OK']];

    $connection->multi();
    $connection->select(5);
    $connection->exec();
    $connection->blpop('q', 0);

    expect($dedicated[0]->log)->toBe(['SELECT 5', 'BLPOP q 0']);
});

it('ignores a select from a discarded or failed multi', function (string $end, array $replies) {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->replies = $replies;

    $connection->multi();
    $connection->select(5);
    $connection->{$end}();
    $connection->blpop('q', 0);

    expect($dedicated[0]->log)->toBe(['BLPOP q 0']);
})->with([
    'discard' => ['discard', []],
    'watch abort' => ['exec', ['EXEC' => null]],
]);

it('fails a blocking call in flight on disconnect and never reuses its connection', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events, hold: true);

    $pop = async(fn () => $connection->blpop('q', 0));
    delay(0);

    // Hang guard only: without the fix the pop would wait forever.
    $watchdog = EventLoop::delay(0.5, fn () => $dedicated[0]->release());

    $connection->disconnect();

    try {
        expect(fn () => $pop->await())->toThrow(RedisInFlightCommandException::class, 'connection lost');
    } finally {
        EventLoop::cancel($watchdog);
    }

    expect($dedicated[0]->log)->toBe(['BLPOP q 0', 'CLOSE']);

    $dedicatedBefore = count($dedicated);
    EventLoop::delay(0.01, fn () => $dedicated[count($dedicated) - 1]->release());
    $connection->blpop('q', 0);

    expect(count($dedicated))->toBe($dedicatedBefore + 1);
});

it('runs a pipeline holding a blocking command in command order', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->replies = ['RPUSH' => 1];

    $replies = $connection->pipeline(function ($pipe) {
        $pipe->rpush('q', 'job');
        $pipe->blpop('q', 0);
    });

    expect($replies)->toBe([1, ['q', 'job']])
        ->and($events->getArrayCopy())->toBe([
            'shared send RPUSH q job',
            'shared done RPUSH q job',
            'dedicated0 send BLPOP q 0',
            'dedicated0 done BLPOP q 0',
        ]);
});

it('drops a failed blocking connection without rebuilding the shared one', function () {
    $events = new ArrayObject;
    $shared = new BlockingFakeLink('shared', $events);
    $shared->release();
    $links = [];
    $connects = 0;

    $connection = new FledgeRedisConnection(new RedisClient($shared), null, function () use ($events, &$links, &$connects) {
        $connects++;
        $link = new BlockingFakeLink('dedicated', $events);
        $link->release();
        $links[] = $link;

        return new RedisClient($link);
    });

    $connection->blpop('q', 0);
    $links[0]->failWith = 'Redis connection lost while BLPOP was in flight';

    expect(fn () => $connection->blpop('q', 0))->toThrow(RedisException::class);

    $connection->blpop('q', 0);
    $connection->get('k');

    expect($connects)->toBe(2)
        ->and($links[1]->log)->toBe(['BLPOP q 0'])
        ->and($shared->log)->toBe(['GET k']);
});

it('keeps the blocking connection after a server error reply', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $connection->blpop('q', 0);
    $dedicated[0]->errorReply = 'WRONGTYPE Operation against a key holding the wrong kind of value';

    expect(fn () => $connection->blpop('q', 0))->toThrow(\Fledge\Async\Redis\Protocol\QueryException::class);

    $dedicated[0]->errorReply = null;
    $connection->blpop('q', 0);

    expect(count($dedicated))->toBe(1)
        ->and($dedicated[0]->log)->toHaveCount(3);
});

it('ignores a select inside multi whose own exec entry is an error', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->replies = ['EXEC' => ['OK', new QueryException('ERR DB index is out of range')]];

    $connection->multi();
    $connection->select(2);
    $connection->select(9999);
    $replies = $connection->exec();
    $connection->blpop('q', 0);

    expect($replies)->toBe(['OK', false])
        ->and($dedicated[0]->log)->toBe(['SELECT 2', 'BLPOP q 0']);
});

it('closes idle blocking connections on disconnect', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->release();

    $connection->blpop('q', 0);
    $connection->disconnect();

    expect($dedicated[0]->log)->toBe(['BLPOP q 0', 'QUIT'])
        ->and($shared->log)->toBe(['QUIT']);
});

it('builds phpredis-style stream reads and routes them by BLOCK', function (Closure $call, string $sent, string $on) {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $call($connection);

    $log = $on === 'shared' ? $shared->log : ($dedicated[0]->log ?? []);

    expect($log)->toBe([$sent])
        ->and(count($dedicated))->toBe($on === 'shared' ? 0 : 1)
        ->and($shared->log)->toHaveCount($on === 'shared' ? 1 : 0);
})->with([
    'xread defaults' => [fn ($c) => $c->xread(['s' => '0']), 'XREAD STREAMS s 0', 'shared'],
    'xread two streams with count' => [fn ($c) => $c->xread(['a' => '0', 'b' => '$'], 5), 'XREAD COUNT 5 STREAMS a b 0 $', 'shared'],
    'xread count 0' => [fn ($c) => $c->xread(['s' => '0'], 0), 'XREAD COUNT 0 STREAMS s 0', 'shared'],
    'xread null count and block' => [fn ($c) => $c->xread(['s' => '0'], null, null), 'XREAD STREAMS s 0', 'shared'],
    'xread block 0' => [fn ($c) => $c->xread(['s' => '$'], -1, 0), 'XREAD BLOCK 0 STREAMS s $', 'dedicated'],
    'xread count and block' => [fn ($c) => $c->xread(['s' => '$'], 2, 100), 'XREAD COUNT 2 BLOCK 100 STREAMS s $', 'dedicated'],
    'xreadgroup defaults' => [fn ($c) => $c->xreadgroup('g', 'c', ['s' => '>']), 'XREADGROUP GROUP g c STREAMS s >', 'shared'],
    'xreadgroup explicit count 1' => [fn ($c) => $c->xreadgroup('g', 'c', ['s' => '>'], 1), 'XREADGROUP GROUP g c COUNT 1 STREAMS s >', 'shared'],
    'xreadgroup count and block' => [fn ($c) => $c->xreadgroup('g', 'c', ['s' => '>'], 10, 500), 'XREADGROUP GROUP g c COUNT 10 BLOCK 500 STREAMS s >', 'dedicated'],
    'xreadgroup null count, block 0' => [fn ($c) => $c->xreadgroup('g', 'c', ['s' => '>'], null, 0), 'XREADGROUP GROUP g c BLOCK 0 STREAMS s >', 'dedicated'],
]);

it('prefixes only the stream keys of a phpredis-style xreadgroup', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events, prefix: 'p:');

    $connection->xreadgroup('g', 'c', ['a' => '>', 'b' => '>'], 1, 0);
    $connection->xread(['a' => '0']);

    expect($dedicated[0]->log)->toBe(['XREADGROUP GROUP g c COUNT 1 BLOCK 0 STREAMS p:a p:b > >'])
        ->and($shared->log)->toBe(['XREAD STREAMS p:a 0']);
});

it('shapes stream read replies like phpredis', function (mixed $raw, array $expected) {
    [$connection, $shared] = blockingConnection($events);
    $shared->replies = ['XREAD' => $raw, 'XREADGROUP' => $raw];

    expect($connection->xread(['s' => '0']))->toBe($expected)
        ->and($connection->xreadgroup('g', 'c', ['s' => '>']))->toBe($expected);
})->with([
    'entries' => [
        [['s', [['1-0', ['f', 'a']], ['2-0', ['f', 'b', 'g', 'c']]]], ['t', [['1-0', ['x', 'y']]]]],
        ['s' => ['1-0' => ['f' => 'a'], '2-0' => ['f' => 'b', 'g' => 'c']], 't' => ['1-0' => ['x' => 'y']]],
    ],
    'timeout or nothing new' => [null, []],
    'empty' => [[], []],
]);

it('answers a negative xreadgroup count or block with a warning and false, like phpredis', function () {
    [$connection, $shared] = blockingConnection($events);
    $warnings = [];

    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = [$level, $message];

        return true;
    });

    try {
        $result = $connection->xreadgroup('g', 'c', ['s' => '>'], -1, -1);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeFalse()
        ->and($warnings)->toBe([[E_USER_WARNING, 'Negative values for COUNT or BLOCK are illegal.']])
        ->and($shared->log)->toBe([]);
});

it('does not retry a blocking call that disconnect() failed, even with command_retries', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events, hold: true, config: ['command_retries' => 3]);

    $pop = async(fn () => $connection->blpop('q', 0));
    delay(0);

    $connection->disconnect();

    try {
        // Without the fix the retry waits on a fresh held connection.
        expect(fn () => $pop->await(new \Fledge\Async\TimeoutCancellation(0.5)))
            ->toThrow(RedisInFlightCommandException::class, 'connection lost');
    } finally {
        foreach ($dedicated as $link) {
            $link->release();
        }
    }

    expect(count($dedicated))->toBe(1);
});

it('still retries a blocking call that failed transiently without a disconnect', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events, config: ['command_retries' => 1]);

    $connection->blpop('q', 0);
    $dedicated[0]->failTimes = 1;

    expect($connection->blpop('q', 0))->toBe(['q', 'job'])
        ->and(count($dedicated))->toBe(2);
});

it('tracks a select sent through executeRaw', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);

    $connection->executeRaw(['SELECT', 3]);
    $connection->blpop('q', 0);

    expect($dedicated[0]->log)->toBe(['SELECT 3', 'BLPOP q 0']);
});

it('tracks a select sent through executeRaw inside the fiber\'s own multi', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->replies = ['EXEC' => ['OK', 'OK']];

    $connection->multi();
    $connection->set('k', 'v');
    $connection->executeRaw(['SELECT', 4]);
    $connection->exec();
    $connection->blpop('q', 0);

    expect($dedicated[0]->log)->toBe(['SELECT 4', 'BLPOP q 0']);
});

it('ignores a raw select that only got queued in a raw multi', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $shared->replies = ['SELECT' => 'QUEUED'];

    $connection->executeRaw(['SELECT', 6]);
    $connection->blpop('q', 0);

    expect($dedicated[0]->log)->toBe(['BLPOP q 0']);
});

it('leaves the raw stream read forms untouched', function () {
    [$connection, $shared, $dedicated] = blockingConnection($events);
    $raw = [['s', [['1-0', ['f', 'a']]]]];
    $shared->replies = ['XREAD' => $raw];

    expect($connection->command('XREAD', ['COUNT', 1, 'STREAMS', 's', '0']))->toBe($raw)
        ->and($connection->executeRaw(['XREAD', 'STREAMS', 's', '0']))->toBe($raw)
        ->and($shared->log)->toBe(['XREAD COUNT 1 STREAMS s 0', 'XREAD STREAMS s 0'])
        ->and(count($dedicated))->toBe(0);
});
