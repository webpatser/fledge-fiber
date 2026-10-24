<?php

use Fledge\Async\Http\Server\Session\Base64UrlSessionIdGenerator;

it('generates valid base64url session ids without extra dependencies', function (int $length) {
    $generator = new Base64UrlSessionIdGenerator($length);

    $id = $generator->generate();

    expect(strlen($id))->toBe($length)
        ->and($generator->validate($id))->toBeTrue()
        ->and($generator->generate())->not->toBe($id);
})->with([24, 48, 64]);
