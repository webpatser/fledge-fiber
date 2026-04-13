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

it('prefix with empty string returns key unchanged', function () {
    $conn = createTestConnection('');

    expect($conn->_prefix('mykey'))->toBe('mykey');
});

it('flattenParameters flattens nested arrays', function () {
    $conn = createTestConnection();
    $method = new ReflectionMethod($conn, 'flattenParameters');

    $result = $method->invoke($conn, ['key', ['a', 'b', 'c']]);

    expect($result)->toBe(['key', 'a', 'b', 'c']);
});

it('flattenParameters converts booleans to ints', function () {
    $conn = createTestConnection();
    $method = new ReflectionMethod($conn, 'flattenParameters');

    $result = $method->invoke($conn, [true, false]);

    expect($result)->toBe([1, 0]);
});

it('flattenParameters emits key and value for assoc arrays', function () {
    $conn = createTestConnection();
    $method = new ReflectionMethod($conn, 'flattenParameters');

    $result = $method->invoke($conn, [['field1' => 'val1', 'field2' => 'val2']]);

    expect($result)->toBe(['field1', 'val1', 'field2', 'val2']);
});

it('pairsToAssociative converts flat pairs to assoc array', function () {
    $conn = createTestConnection();
    $method = new ReflectionMethod($conn, 'pairsToAssociative');

    $result = $method->invoke($conn, ['key1', 'val1', 'key2', 'val2']);

    expect($result)->toBe(['key1' => 'val1', 'key2' => 'val2']);
});

it('pairsToAssociative handles empty array', function () {
    $conn = createTestConnection();
    $method = new ReflectionMethod($conn, 'pairsToAssociative');

    $result = $method->invoke($conn, []);

    expect($result)->toBe([]);
});
