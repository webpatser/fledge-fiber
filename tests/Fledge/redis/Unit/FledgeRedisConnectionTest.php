<?php

use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisPipeline;

use function Fledge\Async\delay;

/**
 * Records the commands in the order they reach the link and answers GET
 * with "v" and everything else with "OK".
 */
final class PipelineReplyLink implements RedisLink
{
    /** @var list<string> */
    public array $log = [];

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);
        $this->log[] = trim($command.' '.implode(' ', $parameters));

        delay(0.001);

        return new RedisValue($command === 'GET' ? 'v' : 'OK');
    }
}

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

it('hands a pipeline to the pipeline callback', function () {
    $conn = createTestConnection();
    $received = null;

    $conn->pipeline(function ($pipe) use (&$received) {
        $received = $pipe;
    });

    expect($received)->toBeInstanceOf(FledgeRedisPipeline::class);
});

it('returns an empty reply array for an empty pipeline callback', function () {
    $conn = createTestConnection();

    $result = $conn->pipeline(fn ($pipe) => 'ignored_callback_return');

    expect($result)->toBe([]);
});

it('returns the exec reply array from the pipeline callback form', function () {
    $link = new PipelineReplyLink;
    $conn = new FledgeRedisConnection(new RedisClient($link));

    $result = $conn->pipeline(function ($pipe) {
        $pipe->set('k', 'v');
        $pipe->get('k');

        return 'ignored_callback_return';
    });

    expect($result)->toBe(['OK', 'v'])
        ->and($link->log)->toBe(['SET k v', 'GET k']);
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
