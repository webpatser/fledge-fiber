<?php

use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisPipeline;

use function Fledge\Async\delay;

/**
 * Answers each command with its own name, completing earlier commands later
 * so the replies arrive in reverse send order.
 */
final class ReversedReplyLink implements RedisLink
{
    /** @var list<string> */
    public array $log = [];

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);
        $this->log[] = $command;

        delay(0.04 - 0.01 * count($this->log));

        return new RedisValue($command);
    }
}

function createTestPipeline(): FledgeRedisPipeline
{
    $connRef = new ReflectionClass(FledgeRedisConnection::class);
    $conn = $connRef->newInstanceWithoutConstructor();

    $prefixProp = $connRef->getProperty('prefix');
    $prefixProp->setValue($conn, '');

    $configProp = $connRef->getProperty('config');
    $configProp->setValue($conn, []);

    return new FledgeRedisPipeline($conn);
}

it('__call returns $this for fluent chaining', function () {
    $pipeline = createTestPipeline();

    $result = $pipeline->get('key');

    expect($result)->toBe($pipeline);
});

it('queues commands via __call', function () {
    $pipeline = createTestPipeline();

    $pipeline->get('key1');
    $pipeline->set('key2', 'value');
    $pipeline->del('key3');

    $ref = new ReflectionClass($pipeline);
    $commands = $ref->getProperty('commands')->getValue($pipeline);

    expect($commands)->toHaveCount(3)
        ->and($commands[0])->toBe(['get', ['key1']])
        ->and($commands[1])->toBe(['set', ['key2', 'value']])
        ->and($commands[2])->toBe(['del', ['key3']]);
});

it('supports method chaining', function () {
    $pipeline = createTestPipeline();

    $result = $pipeline->get('a')->set('b', 1)->del('c');

    expect($result)->toBe($pipeline);

    $ref = new ReflectionClass($pipeline);
    $commands = $ref->getProperty('commands')->getValue($pipeline);

    expect($commands)->toHaveCount(3);
});

/**
 * Fails INCR with an error reply or the connection, answers the rest with
 * their name, completing earlier commands later.
 */
final class FailingIncrLink implements RedisLink
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private bool $dropConnection = false)
    {
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);
        $this->log[] = $command;

        delay(max(0, 0.04 - 0.01 * count($this->log)));

        if ($command === 'INCR') {
            if ($this->dropConnection) {
                throw new RedisConnectionException('Connection lost');
            }

            return new RedisError('ERR value is not an integer or out of range');
        }

        return new RedisValue($command);
    }
}

it('puts false in the slot of a command that fails with an error reply', function () {
    $link = new FailingIncrLink;
    $pipeline = new FledgeRedisPipeline(new FledgeRedisConnection(new RedisClient($link)));

    $result = $pipeline->set('a', 1)->incr('a')->get('a')->exec();

    expect($link->log)->toBe(['SET', 'INCR', 'GET'])
        ->and($result)->toBe(['SET', false, 'GET']);
});

/**
 * Behaves like Redis inside MULTI: queued commands answer QUEUED and EXEC
 * answers one reply per queued command, a nested error for INCR.
 */
final class QueuingFailingIncrLink implements RedisLink
{
    private ?array $queued = null;

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);
        delay(0.001);

        if ($command === 'MULTI') {
            $this->queued = [];

            return new RedisValue('OK');
        }

        if ($command === 'EXEC') {
            [$replies, $this->queued] = [$this->queued, null];

            return new RedisValue($replies);
        }

        if ($this->queued !== null) {
            $this->queued[] = match ($command) {
                'INCR' => new QueryException('ERR value is not an integer or out of range'),
                'GET' => null,
                default => 'OK',
            };

            return new RedisValue('QUEUED');
        }

        return new RedisValue('OK');
    }
}

it('queues pipeline commands inside the owning transaction and reports failures at exec', function () {
    $connection = new FledgeRedisConnection(new RedisClient(new QueuingFailingIncrLink));

    $connection->multi();
    $queued = (new FledgeRedisPipeline($connection))->set('a', 1)->incr('a')->get('missing')->exec();

    expect($queued)->toBe([$connection, $connection, $connection])
        ->and($connection->exec())->toBe(['OK', false, false])
        ->and($connection->ownsTransaction())->toBeFalse();
});

it('still throws when the connection fails during a pipeline', function () {
    $pipeline = new FledgeRedisPipeline(new FledgeRedisConnection(new RedisClient(new FailingIncrLink(dropConnection: true))));

    $pipeline->set('a', 1)->incr('a')->get('a')->exec();
})->throws(RedisConnectionException::class, 'Connection lost');

/**
 * Answers GET and BLPOP with nil (missing key, nil array) and the rest with
 * falsy but non-nil replies.
 */
final class NilReplyLink implements RedisLink
{
    public function execute(string $command, array $parameters): RedisResponse
    {
        delay(0.001);

        return new RedisValue(match (strtoupper($command)) {
            'GET', 'BLPOP', 'BRPOP' => null,
            'EXISTS' => 0,
            'KEYS' => [],
            'MGET' => ['v', null, ['w', null]],
            default => '',
        });
    }
}

it('maps nil replies in a pipeline like phpredis', function () {
    $pipeline = new FledgeRedisPipeline(new FledgeRedisConnection(new RedisClient(new NilReplyLink)));

    $result = $pipeline->get('missing')->blpop('list', 1)->brpop('list', 1)->exists('missing')->keys('none*')->mget(['a', 'b'])->echo('')->exec();

    expect($result)->toBe([false, [], [], 0, [], ['v', false, ['w', false]], '']);
});

it('returns replies in command order when they complete out of order', function () {
    $link = new ReversedReplyLink;
    $pipeline = new FledgeRedisPipeline(new FledgeRedisConnection(new RedisClient($link)));

    $result = $pipeline->set('a', 1)->get('a')->incr('b')->exec();

    expect($link->log)->toBe(['SET', 'GET', 'INCR'])
        ->and($result)->toBe(['SET', 'GET', 'INCR']);
});
