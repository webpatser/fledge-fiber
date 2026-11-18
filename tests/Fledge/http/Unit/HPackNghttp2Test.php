<?php

declare(strict_types=1);

use Fledge\Async\Http\Internal\HPackNative;
use Fledge\Async\Http\Internal\HPackNghttp2;

beforeEach(function () {
    if (! HPackNghttp2::isSupported()) {
        $this->markTestSkipped('FFI or libnghttp2 is not available');
    }
});

function hpackNghttp2Lists(): array
{
    $many = [];
    for ($i = 0; $i < 200; $i++) {
        $many[] = ['x-h'.$i, 'v'.$i];
    }

    return [
        'request' => [[
            [':method', 'GET'],
            [':scheme', 'https'],
            [':path', '/index.html?q=1'],
            [':authority', 'example.com'],
            ['accept', '*/*'],
            ['user-agent', 'fledge'],
        ]],
        'sensitive' => [[
            ['authorization', 'Bearer abc.def.ghi'],
            ['cookie', 'a=1; b=2'],
            ['set-cookie', 'id=xyz; Path=/'],
            ['proxy-authorization', 'Basic Zm9vOmJhcg=='],
        ]],
        'large values' => [[
            ['x-large', str_repeat('a', 16384)],
            ['x-binary', implode(array_map(chr(...), range(1, 255)))],
            ['x-larger-than-table', str_repeat('0123456789', 1000)],
        ]],
        'many small headers' => [$many],
        'empty value' => [[['x-empty', ''], ['x-after', 'value']]],
    ];
}

it('round-trips header lists through one encoder and decoder', function (array $headers) {
    $encoder = new HPackNghttp2;
    $decoder = new HPackNghttp2;
    $reference = new HPackNative;

    // Three blocks on the same contexts exercise the dynamic table on both sides.
    for ($block = 0; $block < 3; $block++) {
        $encoded = $encoder->encode($headers);

        expect($decoder->decode($encoded, 1 << 20))->toBe($headers);
        expect($reference->decode($encoded, 1 << 20))->toBe($headers);
    }
})->with(hpackNghttp2Lists());

it('applies dynamic table size updates at the start of a header block', function () {
    $decoder = new HPackNghttp2;

    // 0x3f 0xe1 0x1f: size update to 4096, then 0x82: static index 2 (:method GET).
    expect($decoder->decode("\x3f\xe1\x1f\x82", 4096))->toBe([[':method', 'GET']]);

    // 0x40 literal with incremental indexing adds x-a: b to the dynamic table (index 62).
    expect($decoder->decode("\x40\x03x-a\x01b", 4096))->toBe([['x-a', 'b']]);
    expect($decoder->decode("\xbe", 4096))->toBe([['x-a', 'b']]);

    // 0x20: size update to 0 evicts the entry; a following static lookup still works.
    expect($decoder->decode("\x20\x82", 4096))->toBe([[':method', 'GET']]);
    expect($decoder->decode("\xbe", 4096))->toBeNull();

    // A size update above the negotiated maximum is a decoding error.
    expect((new HPackNghttp2)->decode("\x3f\xe2\x1f\x82", 4096))->toBeNull();
});

it('frees both nghttp2 contexts on destruct and tolerates a second destruct', function () {
    $hpack = new HPackNghttp2;
    $hpack->decode($hpack->encode([[':method', 'GET'], ['x-a', 'b']]), 4096);

    $deflate = new ReflectionProperty(HPackNghttp2::class, 'deflatePtr');
    $inflate = new ReflectionProperty(HPackNghttp2::class, 'inflatePtr');

    expect(FFI::isNull($deflate->getValue($hpack)))->toBeFalse();
    expect(FFI::isNull($inflate->getValue($hpack)))->toBeFalse();

    $hpack->__destruct();

    expect($deflate->getValue($hpack))->toBeNull();
    expect($inflate->getValue($hpack))->toBeNull();

    $hpack->__destruct();

    expect($deflate->getValue($hpack))->toBeNull();
});

it('forbids cloning so two instances never free the same native contexts', function () {
    $hpack = new HPackNghttp2;
    $error = null;

    try {
        $copy = clone $hpack;

        // Without a clone guard the copy shares the pointers: detach them so
        // the failing run does not double free when both objects destruct.
        foreach (['deflatePtr', 'inflatePtr'] as $name) {
            (new ReflectionProperty(HPackNghttp2::class, $name))->setValue($copy, null);
        }
    } catch (Error $error) {
    }

    // ForbidCloning's __clone is protected, so an outside clone fails on visibility.
    expect($error)->toBeInstanceOf(Error::class);
});

it('forbids serialization', function () {
    expect(fn () => serialize(new HPackNghttp2))
        ->toThrow(Error::class, HPackNghttp2::class.' does not support serialization');
});

it('survives many create, use and release cycles', function () {
    $headers = [[':method', 'POST'], ['x-a', str_repeat('b', 512)]];

    for ($i = 0; $i < 2000; $i++) {
        $hpack = new HPackNghttp2;
        expect($hpack->decode($hpack->encode($headers), 4096))->toBe($headers);
        unset($hpack);
    }
});
