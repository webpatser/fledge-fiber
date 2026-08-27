<?php

use Fledge\Async\Redis\RedisConfig;
use Fledge\Fiber\Redis\FledgeRedisClusterConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;

function buildConfigFor(array $merged): RedisConfig
{
    $connector = new FledgeRedisConnector;
    $method = new ReflectionMethod($connector, 'buildConfig');

    return $method->invoke($connector, $merged);
}

it('builds tcp config from host and port', function () {
    $config = buildConfigFor([
        'host' => '10.0.0.1',
        'port' => 6380,
    ]);

    expect($config->getConnectUri())->toBe('tcp://10.0.0.1:6380')
        ->and($config->getHost())->toBe('10.0.0.1')
        ->and($config->getPort())->toBe(6380)
        ->and($config->usesTls())->toBeFalse();
});

it('builds config with defaults', function () {
    $config = buildConfigFor([]);

    expect($config->getConnectUri())->toBe('tcp://127.0.0.1:6379')
        ->and($config->getPort())->toBe(6379)
        ->and($config->getTimeout())->toBe((float) RedisConfig::DEFAULT_TIMEOUT)
        ->and($config->getReadTimeout())->toBeNull()
        ->and($config->getClientName())->toBeNull()
        ->and($config->usesTcpKeepalive())->toBeFalse()
        ->and($config->getRetryPolicy()->isDefault())->toBeTrue();
});

it('keeps the password out of the connect uri', function () {
    $config = buildConfigFor([
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => 'secret',
    ]);

    expect($config->getConnectUri())->toBe('tcp://127.0.0.1:6379')
        ->and($config->getPassword())->toBe('secret')
        ->and($config->hasPassword())->toBeTrue();
});

it('carries an acl username alongside the password', function () {
    $config = buildConfigFor([
        'host' => '127.0.0.1',
        'username' => 'alice',
        'password' => 'secret',
    ]);

    expect($config->getUsername())->toBe('alice')
        ->and($config->hasUsername())->toBeTrue()
        ->and($config->getPassword())->toBe('secret');
});

it('carries the database', function () {
    $config = buildConfigFor([
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 3,
    ]);

    expect($config->getDatabase())->toBe(3);
});

it('maps timeout to the connect timeout instead of hardcoding it', function () {
    $config = buildConfigFor([
        'host' => '127.0.0.1',
        'timeout' => 2.5,
    ]);

    expect($config->getTimeout())->toBe(2.5);
});

it('builds a unix socket config even when a host is present', function () {
    // Laravel connection configs always carry a host key; the old buildUri()
    // required host to be absent and silently produced a tcp URI instead.
    $config = buildConfigFor([
        'scheme' => 'unix',
        'host' => '127.0.0.1',
        'path' => '/var/run/redis/redis.sock',
    ]);

    expect($config->getConnectUri())->toBe('unix:///var/run/redis/redis.sock');
});

it('builds a unix socket config from a phpredis style host path', function () {
    $config = buildConfigFor([
        'host' => '/var/run/redis/redis.sock',
        'port' => 6379,
    ]);

    expect($config->getConnectUri())->toBe('unix:///var/run/redis/redis.sock');
});

it('flags tls for the tls scheme and normalizes the ssl context', function () {
    $config = buildConfigFor([
        'scheme' => 'tls',
        'host' => 'redis.example.com',
        'port' => 6380,
        'context' => ['ssl' => ['verify_peer' => false]],
    ]);

    expect($config->usesTls())->toBeTrue()
        ->and($config->getConnectUri())->toBe('tcp://redis.example.com:6380')
        ->and($config->getTlsOptions())->toBe(['verify_peer' => false]);
});

it('normalizes a stream wrapped context', function () {
    $config = buildConfigFor([
        'scheme' => 'tls',
        'host' => 'redis.example.com',
        'context' => ['stream' => ['cafile' => '/tmp/ca.pem']],
    ]);

    expect($config->getTlsOptions())->toBe(['cafile' => '/tmp/ca.pem']);
});

it('accepts an already flat context', function () {
    $config = buildConfigFor([
        'scheme' => 'tls',
        'host' => 'redis.example.com',
        'context' => ['verify_peer_name' => false],
    ]);

    expect($config->getTlsOptions())->toBe(['verify_peer_name' => false]);
});

it('carries read timeout, client name, keepalive and retry options', function () {
    $config = buildConfigFor([
        'host' => '127.0.0.1',
        'read_timeout' => 1.5,
        'name' => 'fledge-worker',
        'tcp_keepalive' => 1,
        'max_retries' => 4,
        'retry_interval' => 100,
        'backoff_algorithm' => 'exponential',
        'backoff_base' => 50,
        'backoff_cap' => 2000,
    ]);

    $policy = $config->getRetryPolicy();

    expect($config->getReadTimeout())->toBe(1.5)
        ->and($config->getClientName())->toBe('fledge-worker')
        ->and($config->usesTcpKeepalive())->toBeTrue()
        ->and($policy->maxRetries)->toBe(4)
        ->and($policy->retryIntervalSeconds)->toBe(0.1)
        ->and($policy->backoffAlgorithm)->toBe('exponential')
        ->and($policy->backoffBase)->toBe(0.05)
        ->and($policy->backoffCap)->toBe(2.0);
});

it('builds a cluster connection without contacting any node', function () {
    $connector = new FledgeRedisConnector;

    $connection = $connector->connectToCluster(
        [
            ['host' => '127.0.0.1', 'port' => 17000],
            ['host' => '127.0.0.1', 'port' => 17001],
        ],
        [],
        ['prefix' => 'app:'],
    );

    expect($connection)->toBeInstanceOf(FledgeRedisClusterConnection::class)
        ->and($connection->getPrefix())->toBe('app:')
        ->and($connection->isCluster())->toBeTrue();
});

it('rejects SELECT to a non-zero database on a cluster connection', function () {
    $connector = new FledgeRedisConnector;

    $connection = $connector->connectToCluster(
        [['host' => '127.0.0.1', 'port' => 17000]],
        [],
        [],
    );

    $connection->select(3);
})->throws(InvalidArgumentException::class, 'Redis Cluster does not support SELECT');

it('allows SELECT 0 on a cluster connection as a no-op', function () {
    $connector = new FledgeRedisConnector;

    $connection = $connector->connectToCluster(
        [['host' => '127.0.0.1', 'port' => 17000]],
        [],
        [],
    );

    expect($connection->select(0))->toBeNull();
});
