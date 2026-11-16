<?php

use Fledge\Async\Redis\Connection\RedisLink;
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

it('returns replies in command order when they complete out of order', function () {
    $link = new ReversedReplyLink;
    $pipeline = new FledgeRedisPipeline(new FledgeRedisConnection(new RedisClient($link)));

    $result = $pipeline->set('a', 1)->get('a')->incr('b')->exec();

    expect($link->log)->toBe(['SET', 'GET', 'INCR'])
        ->and($result)->toBe(['SET', 'GET', 'INCR']);
});
