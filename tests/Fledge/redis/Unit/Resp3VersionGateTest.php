<?php

use Fledge\Async\Redis\Protocol\Resp3ExtensionParser;

/*
 * The version gate exists because resp3 before 0.2.0 returns an error reply
 * nested inside an aggregate (a failed command in an EXEC reply) as a plain
 * string, so a failed command would read as a value, and before 0.1.4 also
 * corrupts the state machine on nested RESP2 nulls. SocketRedisConnection
 * falls back to the pure-PHP RespParser when the loaded extension is too old.
 * These tests are pure and run whether or not the extension is loaded.
 */

it('supports only extension versions at or above the minimum', function (?string $version, bool $supported) {
    expect(Resp3ExtensionParser::versionIsSupported($version))->toBe($supported);
})->with([
    'missing version' => [null, false],
    'nested-null bug 0.1.3' => ['0.1.3', false],
    'nested errors as strings 0.1.4' => ['0.1.4', false],
    'later 0.1 patch 0.1.10' => ['0.1.10', false],
    'first fixed 0.2.0' => ['0.2.0', true],
    'later patch 0.2.1' => ['0.2.1', true],
    'future stable 1.0.0' => ['1.0.0', true],
]);

it('names 0.2.0 as the minimum version', function () {
    expect(Resp3ExtensionParser::MINIMUM_VERSION)->toBe('0.2.0');
});

it('isUsable agrees with the version gate for the loaded extension', function () {
    $expected = extension_loaded('resp3')
        && Resp3ExtensionParser::versionIsSupported(phpversion('resp3') ?: null);

    expect(Resp3ExtensionParser::isUsable())->toBe($expected);
});

it('refuses construction on an unsupported extension version', function () {
    new Resp3ExtensionParser(static function (): void {});
})->skip(
    fn () => extension_loaded('resp3') && Resp3ExtensionParser::versionIsSupported(phpversion('resp3') ?: null),
    'a supported resp3 extension is loaded',
)->throws(LogicException::class);
