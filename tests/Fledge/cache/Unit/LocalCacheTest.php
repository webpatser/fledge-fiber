<?php

use Fledge\Async\Cache\CacheException;
use Fledge\Async\Cache\LocalCache;

it('returns null for missing key', function () {
    $cache = new LocalCache;

    expect($cache->get('missing'))->toBeNull();
});

it('stores and retrieves a value', function () {
    $cache = new LocalCache;
    $cache->set('key', 'value');

    expect($cache->get('key'))->toBe('value');
});

it('overwrites existing key', function () {
    $cache = new LocalCache;
    $cache->set('key', 'old');
    $cache->set('key', 'new');

    expect($cache->get('key'))->toBe('new');
});

it('delete returns true for existing key', function () {
    $cache = new LocalCache;
    $cache->set('key', 'value');

    expect($cache->delete('key'))->toBeTrue();
    expect($cache->get('key'))->toBeNull();
});

it('delete returns false for missing key', function () {
    $cache = new LocalCache;

    expect($cache->delete('nope'))->toBeFalse();
});

it('counts items', function () {
    $cache = new LocalCache;

    expect($cache->count())->toBe(0);

    $cache->set('a', 1);
    $cache->set('b', 2);

    expect($cache->count())->toBe(2);
});

it('evicts LRU entry when size limit reached', function () {
    $cache = new LocalCache(sizeLimit: 3);

    $cache->set('a', 1);
    $cache->set('b', 2);
    $cache->set('c', 3);
    $cache->set('d', 4); // should evict 'a'

    expect($cache->get('a'))->toBeNull()
        ->and($cache->get('b'))->toBe(2)
        ->and($cache->get('c'))->toBe(3)
        ->and($cache->get('d'))->toBe(4);
});

it('promotes accessed key to MRU so it survives eviction', function () {
    $cache = new LocalCache(sizeLimit: 2);

    $cache->set('a', 1);
    $cache->set('b', 2);

    // Access 'a' to promote it to MRU
    $cache->get('a');

    // Adding 'c' should evict 'b' (LRU), not 'a'
    $cache->set('c', 3);

    expect($cache->get('a'))->toBe(1)
        ->and($cache->get('b'))->toBeNull()
        ->and($cache->get('c'))->toBe(3);
});

it('throws CacheException when storing null', function () {
    $cache = new LocalCache;
    $cache->set('key', null);
})->throws(CacheException::class);

it('throws Error for negative TTL', function () {
    $cache = new LocalCache;
    $cache->set('key', 'value', -1);
})->throws(\Error::class);

it('item with TTL 0 is accessible at same second', function () {
    $cache = new LocalCache;
    $cache->set('key', 'value', 0);

    // time() + 0 = now, and time() > expiry is false when equal
    expect($cache->get('key'))->toBe('value');
});

it('iterates from LRU to MRU', function () {
    $cache = new LocalCache;
    $cache->set('first', 1);
    $cache->set('second', 2);
    $cache->set('third', 3);

    $keys = [];
    foreach ($cache as $key => $value) {
        $keys[] = $key;
    }

    expect($keys)->toBe(['first', 'second', 'third']);
});

it('stores various types', function () {
    $cache = new LocalCache;

    $cache->set('int', 42);
    $cache->set('float', 3.14);
    $cache->set('array', [1, 2, 3]);
    $cache->set('bool', true);
    $cache->set('object', (object) ['x' => 1]);

    expect($cache->get('int'))->toBe(42)
        ->and($cache->get('float'))->toBe(3.14)
        ->and($cache->get('array'))->toBe([1, 2, 3])
        ->and($cache->get('bool'))->toBeTrue()
        ->and($cache->get('object')->x)->toBe(1);
});
