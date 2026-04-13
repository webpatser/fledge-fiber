<?php

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisPipeline;

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
