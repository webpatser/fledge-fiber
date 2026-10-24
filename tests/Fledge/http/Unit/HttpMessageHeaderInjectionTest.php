<?php

use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\InvalidHeaderException;

it('rejects CR, LF and NUL in header values', function (string $value) {
    $request = new Request('http://example.com/');

    expect(fn () => $request->setHeader('X-Test', $value))->toThrow(InvalidHeaderException::class)
        ->and(fn () => $request->addHeader('X-Test', [$value]))->toThrow(InvalidHeaderException::class)
        ->and($request->hasHeader('X-Test'))->toBeFalse();
})->with([
    'lone cr' => "a\rb",
    'lone lf' => "a\nb",
    'crlf' => "a\r\nInjected: 1",
    'nul' => "a\0b",
]);

it('rejects CR, LF and NUL in header names', function (string $name) {
    $request = new Request('http://example.com/');

    expect(fn () => $request->setHeader($name, 'v'))->toThrow(InvalidHeaderException::class);
})->with([
    'cr' => "X-A\rB",
    'lf' => "X-A\nB",
    'nul' => "X-A\0B",
]);

it('still accepts ordinary headers', function () {
    $request = new Request('http://example.com/');
    $request->setHeader('X-Test', "tab\tseparated value");

    expect($request->getHeader('X-Test'))->toBe("tab\tseparated value");
});
