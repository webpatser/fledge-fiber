<?php

declare(strict_types=1);

use Fledge\Async\Internal\ConcurrentArrayIterator;
use Fledge\Async\Pipeline;

/*
 * Pipeline is declared `final` (matching upstream amphp/pipeline, which is also
 * final). The upstream `: self` to `: static` change is therefore a style/parity
 * normalization: on a final class `static` always resolves to the class itself.
 * A real subclass cannot be defined, so these tests assert the configuration
 * methods are fluent and return the called class via the `static` return type.
 */

function makeStaticReturnPipeline(): Pipeline
{
    return new Pipeline(new ConcurrentArrayIterator([1, 2, 3]));
}

it('returns the called class from buffer()', function () {
    $pipeline = makeStaticReturnPipeline();

    expect($pipeline->buffer(2))
        ->toBeInstanceOf(Pipeline::class)
        ->toBe($pipeline);
});

it('returns the called class from concurrent()', function () {
    $pipeline = makeStaticReturnPipeline();

    expect($pipeline->concurrent(2))
        ->toBeInstanceOf(Pipeline::class)
        ->toBe($pipeline);
});

it('returns the called class from sequential()', function () {
    $pipeline = makeStaticReturnPipeline();

    expect($pipeline->sequential())
        ->toBeInstanceOf(Pipeline::class)
        ->toBe($pipeline);
});

it('returns the called class from ordered()', function () {
    $pipeline = makeStaticReturnPipeline();

    expect($pipeline->ordered())
        ->toBeInstanceOf(Pipeline::class)
        ->toBe($pipeline);
});

it('returns the called class from unordered()', function () {
    $pipeline = makeStaticReturnPipeline();

    expect($pipeline->unordered())
        ->toBeInstanceOf(Pipeline::class)
        ->toBe($pipeline);
});

it('declares the static return type on all five configuration methods', function () {
    $reflection = new ReflectionClass(Pipeline::class);

    foreach (['buffer', 'concurrent', 'sequential', 'ordered', 'unordered'] as $method) {
        $returnType = $reflection->getMethod($method)->getReturnType();

        expect($returnType)->toBeInstanceOf(ReflectionNamedType::class);
        expect($returnType->getName())->toBe('static');
    }
});
