<?php

declare(strict_types=1);

use Fledge\Async\Http\Internal\HPackNative;

it('huffman-encodes every byte value without a chr() deprecation', function () {
    $deprecations = [];

    set_error_handler(function (int $severity, string $message) use (&$deprecations): bool {
        $deprecations[$message] = true;

        return true;
    }, E_DEPRECATED | E_USER_DEPRECATED);

    try {
        $input = implode(array_map(chr(...), range(0, 255)));
        $encoded = HPackNative::huffmanEncode($input);

        expect(HPackNative::huffmanDecode($encoded))->toBe($input);
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBe([], 'chr() deprecation triggered: '.implode(', ', array_keys($deprecations)));
});
