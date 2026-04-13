<?php

use Fledge\Async\Cache\CacheException;
use Fledge\Async\Cache\LocalCache;
use Fledge\Async\Cache\StringCacheAdapter;

it('returns string value', function () {
    $inner = new LocalCache;
    $inner->set('key', 'hello');

    $adapter = new StringCacheAdapter($inner);

    expect($adapter->get('key'))->toBe('hello');
});

it('returns null for missing key', function () {
    $adapter = new StringCacheAdapter(new LocalCache);

    expect($adapter->get('missing'))->toBeNull();
});

it('throws CacheException when inner cache returns non-string', function () {
    $inner = new LocalCache;
    $inner->set('key', 42); // integer, not string

    $adapter = new StringCacheAdapter($inner);
    $adapter->get('key');
})->throws(CacheException::class);

it('set delegates to inner cache', function () {
    $inner = new LocalCache;
    $adapter = new StringCacheAdapter($inner);

    $adapter->set('key', 'value');

    expect($inner->get('key'))->toBe('value');
});

it('delete delegates to inner cache', function () {
    $inner = new LocalCache;
    $inner->set('key', 'value');

    $adapter = new StringCacheAdapter($inner);

    expect($adapter->delete('key'))->toBeTrue();
    expect($inner->get('key'))->toBeNull();
});
