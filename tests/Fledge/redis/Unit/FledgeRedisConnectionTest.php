<?php

use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisPipeline;

/**
 * Create a testable connection without a real RedisClient.
 * RedisClient is final and cannot be mocked.
 */
function createTestConnection(string $prefix = ''): FledgeRedisConnection
{
    $ref = new ReflectionClass(FledgeRedisConnection::class);
    $conn = $ref->newInstanceWithoutConstructor();

    // Set protected properties directly
    $prefixProp = $ref->getProperty('prefix');
    $prefixProp->setValue($conn, $prefix);

    $configProp = $ref->getProperty('config');
    $configProp->setValue($conn, []);

    return $conn;
}

it('returns prefix', function () {
    $conn = createTestConnection('app:');

    expect($conn->getPrefix())->toBe('app:');
});

it('returns empty prefix when none set', function () {
    $conn = createTestConnection('');

    expect($conn->getPrefix())->toBe('');
});

it('prefixes keys', function () {
    $conn = createTestConnection('cache:');

    expect($conn->_prefix('users'))->toBe('cache:users');
});

it('returns pipeline when no callback given', function () {
    $conn = createTestConnection();

    expect($conn->pipeline())->toBeInstanceOf(FledgeRedisPipeline::class);
});

it('executes pipeline callback directly', function () {
    $conn = createTestConnection();

    $result = $conn->pipeline(fn ($c) => 'callback_result');

    expect($result)->toBe('callback_result');
});
