<?php

use Fledge\Async\Cache\NullCache;

it('get always returns null', function () {
    $cache = new NullCache;

    $cache->set('key', 'value');

    expect($cache->get('key'))->toBeNull();
});

it('set does not throw', function () {
    $cache = new NullCache;

    $cache->set('key', 'value', 60);

    expect(true)->toBeTrue(); // no exception
});

it('delete always returns false', function () {
    $cache = new NullCache;

    expect($cache->delete('anything'))->toBeFalse();
});
