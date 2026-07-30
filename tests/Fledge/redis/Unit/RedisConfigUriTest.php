<?php

use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisException;

it('decodes a percent-encoded password', function () {
    $password = 'p@ss:w/rd+v1=';

    $config = RedisConfig::fromUri('redis://:'.rawurlencode($password).'@redis.example.com:6379');

    expect($config->getPassword())->toBe($password);
});

it('does not split on an encoded colon inside the username', function () {
    $config = RedisConfig::fromUri('redis://'.rawurlencode('user:name').':'.rawurlencode('secret').'@localhost');

    expect($config->getUsername())->toBe('user:name')
        ->and($config->getPassword())->toBe('secret');
});

it('captures an acl username from the user information', function () {
    $config = RedisConfig::fromUri('redis://alice:secret@localhost:6379');

    expect($config->getUsername())->toBe('alice')
        ->and($config->hasUsername())->toBeTrue()
        ->and($config->getPassword())->toBe('secret');
});

it('falls back to query parameters for credentials', function () {
    $config = RedisConfig::fromUri('redis://localhost:6379?username=alice&password=secret');

    expect($config->getUsername())->toBe('alice')
        ->and($config->getPassword())->toBe('secret');
});

it('reports no username when only a password is supplied', function () {
    $config = RedisConfig::fromUri('redis://:secret@localhost:6379');

    expect($config->hasUsername())->toBeFalse()
        ->and($config->getUsername())->toBe('')
        ->and($config->getPassword())->toBe('secret');
});

it('accepts the rediss scheme and flags tls', function () {
    $config = RedisConfig::fromUri('rediss://:secret@redis.example.com:6380');

    expect($config->usesTls())->toBeTrue()
        ->and($config->getConnectUri())->toBe('tcp://redis.example.com:6380')
        ->and($config->getHost())->toBe('redis.example.com')
        ->and($config->getPassword())->toBe('secret');
});

it('does not flag tls for plain schemes', function (string $uri) {
    expect(RedisConfig::fromUri($uri)->usesTls())->toBeFalse();
})->with([
    'redis://localhost',
    'tcp://localhost',
    'unix:///tmp/redis.sock',
]);

it('rejects an unknown scheme', function () {
    expect(fn () => RedisConfig::fromUri('http://localhost:6379'))
        ->toThrow(RedisException::class);
});

it('keeps parsing database and host as before', function () {
    $config = RedisConfig::fromUri('redis://localhost:6379/3');

    expect($config->getDatabase())->toBe(3)
        ->and($config->getHost())->toBe('localhost')
        ->and($config->getConnectUri())->toBe('tcp://localhost:6379');
});

it('supports unix sockets without credentials', function () {
    $config = RedisConfig::fromUri('unix:///tmp/redis.sock');

    expect($config->getConnectUri())->toBe('unix:///tmp/redis.sock')
        ->and($config->getPassword())->toBe('')
        ->and($config->getUsername())->toBe('');
});
