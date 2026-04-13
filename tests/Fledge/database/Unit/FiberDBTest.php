<?php

use Fledge\Fiber\Database\FiberDB;

it('returns results in order', function () {
    $results = FiberDB::concurrent(
        fn () => 'first',
        fn () => 'second',
        fn () => 'third',
    );

    expect($results)->toBe(['first', 'second', 'third']);
});

it('handles single operation', function () {
    expect(FiberDB::concurrent(fn () => 42))->toBe([42]);
});

it('handles mixed return types', function () {
    $results = FiberDB::concurrent(
        fn () => ['a', 'b'],
        fn () => 42,
        fn () => null,
    );

    expect($results[0])->toBe(['a', 'b'])
        ->and($results[1])->toBe(42)
        ->and($results[2])->toBeNull();
});

it('propagates exceptions', function () {
    FiberDB::concurrent(
        fn () => 'ok',
        fn () => throw new RuntimeException('test error'),
    );
})->throws(RuntimeException::class, 'test error');
