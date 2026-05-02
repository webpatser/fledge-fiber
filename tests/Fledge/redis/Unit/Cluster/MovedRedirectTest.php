<?php

use Fledge\Async\Redis\Cluster\AskRedirect;
use Fledge\Async\Redis\Cluster\MovedRedirect;
use Fledge\Async\Redis\Protocol\RedisError;

it('parses a MOVED redirect into slot, host, port', function () {
    $error = new RedisError('MOVED 3999 127.0.0.1:6381');

    $redirect = MovedRedirect::tryParse($error);

    expect($redirect)->not->toBeNull()
        ->and($redirect->slot)->toBe(3999)
        ->and($redirect->host)->toBe('127.0.0.1')
        ->and($redirect->port)->toBe(6381)
        ->and($redirect->endpoint())->toBe('127.0.0.1:6381');
});

it('parses an ASK redirect', function () {
    $error = new RedisError('ASK 7000 10.0.0.5:6379');

    $redirect = AskRedirect::tryParse($error);

    expect($redirect)->not->toBeNull()
        ->and($redirect->slot)->toBe(7000)
        ->and($redirect->endpoint())->toBe('10.0.0.5:6379');
});

it('returns null when a non-redirect error is parsed', function () {
    $error = new RedisError('CLUSTERDOWN The cluster is down');

    expect(MovedRedirect::tryParse($error))->toBeNull()
        ->and(AskRedirect::tryParse($error))->toBeNull();
});

it('strips IPv6 brackets from the host field', function () {
    $error = new RedisError('MOVED 100 [::1]:6379');

    $redirect = MovedRedirect::tryParse($error);

    expect($redirect->host)->toBe('::1')
        ->and($redirect->port)->toBe(6379);
});
