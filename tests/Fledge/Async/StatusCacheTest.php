<?php

declare(strict_types=1);

use Fledge\Async\File\Driver\BlockingFilesystemDriver;
use Fledge\Async\File\Driver\StatusCachingFilesystemDriver;
use Fledge\Async\File\Internal\Cache;

/*
 * \time() has one-second granularity, so a TTL of 1 second is only guaranteed to be over after just more
 * than two seconds. Each test that checks expiry therefore waits 2.1 seconds.
 */

it('returns a value before its TTL and null after it', function () {
    $cache = new Cache(1, 16);
    $cache->set('a', ['x' => 1], 1);
    $cache->set('forever', ['x' => 2], null);

    expect($cache->get('a'))->toBe(['x' => 1]);

    usleep(2_100_000);

    expect($cache->get('a'))->toBeNull()
        ->and($cache->get('forever'))->toBe(['x' => 2]);
});

it('evicts the oldest entry when the maximum size is reached', function () {
    $cache = new Cache(1, 2);
    $cache->set('a', [1], null);
    $cache->set('b', [2], null);
    $cache->set('c', [3], null);

    expect($cache->get('a'))->toBeNull()
        ->and($cache->get('b'))->toBe([2])
        ->and($cache->get('c'))->toBe([3]);
});

it('serves a repeated status from cache within the window and re-reads after it', function () {
    $path = sys_get_temp_dir() . '/status-cache-test-' . bin2hex(random_bytes(6));
    file_put_contents($path, 'abc');

    try {
        $driver = new StatusCachingFilesystemDriver(new BlockingFilesystemDriver());

        expect($driver->getStatus($path)['size'])->toBe(3);

        file_put_contents($path, 'abcdef');
        clearstatcache();

        expect($driver->getStatus($path)['size'])->toBe(3);

        usleep(2_100_000);

        expect($driver->getStatus($path)['size'])->toBe(6);
    } finally {
        @unlink($path);
    }
});
