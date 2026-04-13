<?php

use Fledge\Async\Cache\LocalCache;
use Fledge\Async\Cache\PrefixCache;

it('returns the key prefix', function () {
    $cache = new PrefixCache(new LocalCache, 'app:');

    expect($cache->getKeyPrefix())->toBe('app:');
});

it('prepends prefix on get', function () {
    $inner = new LocalCache;
    $inner->set('prefix:key', 'found');

    $cache = new PrefixCache($inner, 'prefix:');

    expect($cache->get('key'))->toBe('found');
});

it('prepends prefix on set', function () {
    $inner = new LocalCache;
    $cache = new PrefixCache($inner, 'ns:');

    $cache->set('key', 'value');

    // The inner cache should have 'ns:key'
    expect($inner->get('ns:key'))->toBe('value');
});

it('prepends prefix on delete', function () {
    $inner = new LocalCache;
    $inner->set('ns:key', 'value');

    $cache = new PrefixCache($inner, 'ns:');

    expect($cache->delete('key'))->toBeTrue();
    expect($inner->get('ns:key'))->toBeNull();
});

it('different prefixes isolate keys', function () {
    $inner = new LocalCache;
    $cacheA = new PrefixCache($inner, 'a:');
    $cacheB = new PrefixCache($inner, 'b:');

    $cacheA->set('key', 'from-a');
    $cacheB->set('key', 'from-b');

    expect($cacheA->get('key'))->toBe('from-a')
        ->and($cacheB->get('key'))->toBe('from-b');
});
