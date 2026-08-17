<?php

use Fledge\Async\Redis\Protocol\Resp3ExtensionParser;

/*
 * The version gate exists because resp3 < 0.1.4 corrupts the state machine on
 * RESP2 nulls nested inside aggregates; SocketRedisConnection falls back to
 * the pure-PHP RespParser when the loaded extension is too old. These tests
 * are pure and run whether or not the extension is loaded.
 */

it('supports only extension versions at or above the minimum', function (?string $version, bool $supported) {
    expect(Resp3ExtensionParser::versionIsSupported($version))->toBe($supported);
})->with([
    'missing version' => [null, false],
    'buggy 0.1.3' => ['0.1.3', false],
    'first fixed 0.1.4' => ['0.1.4', true],
    'later patch 0.1.10' => ['0.1.10', true],
    'future stable 1.0.0' => ['1.0.0', true],
]);

it('isUsable agrees with the version gate for the loaded extension', function () {
    $expected = extension_loaded('resp3')
        && Resp3ExtensionParser::versionIsSupported(phpversion('resp3') ?: null);

    expect(Resp3ExtensionParser::isUsable())->toBe($expected);
});
