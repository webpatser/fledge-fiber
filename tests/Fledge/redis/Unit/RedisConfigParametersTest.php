<?php

use Fledge\Async\Redis\Connection\RetryPolicy;
use Fledge\Async\Redis\RedisConfig;

it('derives a unix connect uri', function (array $params, string $expected) {
    expect(RedisConfig::fromParameters($params)->getConnectUri())->toBe($expected);
})->with([
    'unix scheme with path' => [
        ['scheme' => 'unix', 'path' => '/tmp/redis.sock'],
        'unix:///tmp/redis.sock',
    ],
    'unix scheme with host as path' => [
        ['scheme' => 'unix', 'host' => '/tmp/redis.sock'],
        'unix:///tmp/redis.sock',
    ],
    'unix scheme with host and path' => [
        ['scheme' => 'unix', 'host' => 'localhost', 'path' => '/tmp/redis.sock'],
        'unix:///tmp/redis.sock',
    ],
    'predis style path without scheme' => [
        ['host' => 'localhost', 'path' => '/tmp/redis.sock'],
        'unix:///tmp/redis.sock',
    ],
    'phpredis style path host' => [
        ['host' => '/tmp/redis.sock', 'port' => 6379],
        'unix:///tmp/redis.sock',
    ],
    'port zero with path-looking host' => [
        ['host' => 'var/run/redis.sock', 'port' => 0],
        'unix://var/run/redis.sock',
    ],
]);

it('does not treat a normal host with port zero as a socket', function () {
    $config = RedisConfig::fromParameters(['host' => 'redis.example.com', 'port' => 0]);

    expect($config->getConnectUri())->toBe('tcp://redis.example.com:0');
});

it('derives tls from the scheme', function (string $scheme, bool $tls) {
    $config = RedisConfig::fromParameters([
        'scheme' => $scheme,
        'host' => 'redis.example.com',
        'port' => 6380,
    ]);

    expect($config->usesTls())->toBe($tls)
        ->and($config->getConnectUri())->toBe('tcp://redis.example.com:6380');
})->with([
    'tls' => ['tls', true],
    'rediss' => ['rediss', true],
    'tcp' => ['tcp', false],
]);

it('carries the acl username and password', function () {
    $config = RedisConfig::fromParameters([
        'host' => 'localhost',
        'username' => 'alice',
        'password' => 'p@ss:w/rd+v1=',
    ]);

    expect($config->getUsername())->toBe('alice')
        ->and($config->hasUsername())->toBeTrue()
        ->and($config->getPassword())->toBe('p@ss:w/rd+v1=')
        ->and($config->hasPassword())->toBeTrue();
});

it('maps the timeout to the connect timeout', function () {
    expect(RedisConfig::fromParameters(['host' => 'localhost', 'timeout' => 1.5])->getTimeout())->toBe(1.5);
});

it('falls back to the default timeout when the timeout is zero or negative', function (int|float $timeout) {
    expect(RedisConfig::fromParameters(['host' => 'localhost', 'timeout' => $timeout])->getTimeout())
        ->toBe((float) RedisConfig::DEFAULT_TIMEOUT);
})->with([0, -1, 0.0]);

it('normalizes read_timeout with phpredis semantics', function (int|float|null $readTimeout, ?float $expected) {
    $params = ['host' => 'localhost'];

    if ($readTimeout !== null) {
        $params['read_timeout'] = $readTimeout;
    }

    expect(RedisConfig::fromParameters($params)->getReadTimeout())->toBe($expected);
})->with([
    'absent means no limit' => [null, null],
    'zero means no limit' => [0, null],
    'negative means no limit' => [-1, null],
    'positive is kept' => [2.5, 2.5],
]);

it('keeps raw tls options', function () {
    $options = ['verify_peer' => false, 'cafile' => '/tmp/ca.pem'];

    $config = RedisConfig::fromParameters([
        'scheme' => 'tls',
        'host' => 'localhost',
        'context' => $options,
    ]);

    expect($config->getTlsOptions())->toBe($options);
});

it('carries the client name and tcp keepalive flag', function () {
    $config = RedisConfig::fromParameters([
        'host' => 'localhost',
        'name' => 'worker-1',
        'tcp_keepalive' => 1,
    ]);

    expect($config->getClientName())->toBe('worker-1')
        ->and($config->usesTcpKeepalive())->toBeTrue();
});

it('treats an empty client name as unset', function () {
    expect(RedisConfig::fromParameters(['host' => 'localhost', 'name' => ''])->getClientName())->toBeNull();
});

it('builds a retry policy from millisecond options', function () {
    $policy = RedisConfig::fromParameters([
        'host' => 'localhost',
        'command_retries' => 2,
        'max_retries' => 5,
        'retry_interval' => 250,
        'backoff_algorithm' => 'decorrelated_jitter',
        'backoff_base' => 100,
        'backoff_cap' => 1500,
    ])->getRetryPolicy();

    expect($policy->commandRetries)->toBe(2)
        ->and($policy->maxRetries)->toBe(5)
        ->and($policy->retryIntervalSeconds)->toBe(0.25)
        ->and($policy->backoffAlgorithm)->toBe('decorrelated_jitter')
        ->and($policy->backoffBase)->toBe(0.1)
        ->and($policy->backoffCap)->toBe(1.5);
});

it('rejects an unknown backoff algorithm', function () {
    RedisConfig::fromParameters(['host' => 'localhost', 'backoff_algorithm' => 'warp']);
})->throws(InvalidArgumentException::class, 'not a valid backoff algorithm');

it('accepts every documented backoff algorithm', function (string $algorithm) {
    expect(RedisConfig::fromParameters(['host' => 'localhost', 'backoff_algorithm' => $algorithm])
        ->getRetryPolicy()->backoffAlgorithm)->toBe($algorithm);
})->with(RetryPolicy::BACKOFF_ALGORITHMS);
