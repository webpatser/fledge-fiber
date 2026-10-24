<?php

use Fledge\Async\Redis\RedisClient;

use function Fledge\Async\Redis\createRedisClusterClient;

it('rejects an empty seed list', function () {
    createRedisClusterClient([]);
})->throws(InvalidArgumentException::class, 'at least one seed');

it('rejects an invalid seed entry', function () {
    createRedisClusterClient(['127.0.0.1:17000', 42]);
})->throws(InvalidArgumentException::class, 'non-empty string');

it('builds a client from host:port and URI seeds without connecting', function () {
    $client = createRedisClusterClient(['127.0.0.1:1', 'tcp://127.0.0.1:2'], parserFactory: fn () => throw new LogicException('unused'));

    expect($client)->toBeInstanceOf(RedisClient::class);
});

function endpointConfigOf(RedisClient $client, string $endpoint): Fledge\Async\Redis\RedisConfig|string
{
    $link = (new ReflectionProperty(RedisClient::class, 'link'))->getValue($client);
    $factory = (new ReflectionProperty($link, 'configForEndpoint'))->getValue($link);

    return $factory($endpoint);
}

it('applies the first seed auth and TLS options to discovered nodes', function () {
    $client = createRedisClusterClient(['rediss://app:s3cret@10.0.0.1:7000?timeout=2']);

    $config = endpointConfigOf($client, '10.0.0.2:7001');

    expect($config)->toBeInstanceOf(Fledge\Async\Redis\RedisConfig::class)
        ->and($config->getConnectUri())->toBe('tcp://10.0.0.2:7001')
        ->and($config->getHost())->toBe('10.0.0.2')
        ->and($config->getPort())->toBe(7001)
        ->and($config->usesTls())->toBeTrue()
        ->and($config->getTlsContext()?->getPeerName())->toBe('10.0.0.2')
        ->and($config->getUsername())->toBe('app')
        ->and($config->getPassword())->toBe('s3cret')
        ->and($config->getTimeout())->toBe(2.0);
});

it('accepts IPv6 seeds and builds bracketed endpoint URIs', function () {
    $client = createRedisClusterClient(['[::1]:7000']);

    expect(endpointConfigOf($client, '[::2]:7001')->getConnectUri())->toBe('tcp://[::2]:7001');
});

it('rejects seeds a cluster cannot use', function (string $seed) {
    createRedisClusterClient([$seed]);
})->with([
    'unknown scheme' => ['http://127.0.0.1:7000'],
    'unix socket' => ['unix:///tmp/redis.sock'],
    'non-zero database' => ['redis://127.0.0.1:7000/2'],
])->throws(InvalidArgumentException::class);

it('keeps a custom endpoint config builder', function () {
    $client = createRedisClusterClient(['127.0.0.1:7000'], fn (string $endpoint) => 'tcp://custom-'.$endpoint);

    expect(endpointConfigOf($client, 'x:1'))->toBe('tcp://custom-x:1');
});
