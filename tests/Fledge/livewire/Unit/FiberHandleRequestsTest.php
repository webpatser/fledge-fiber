<?php

use Fledge\Fiber\Livewire\Mechanisms\FiberHandleRequests;

it('extends Livewire HandleRequests', function () {
    expect(FiberHandleRequests::class)
        ->toExtend(\Livewire\Mechanisms\HandleRequests\HandleRequests::class);
});

it('has processComponent method', function () {
    $ref = new ReflectionMethod(FiberHandleRequests::class, 'processComponent');

    expect($ref->getNumberOfParameters())->toBe(1)
        ->and($ref->isProtected())->toBeTrue();
});

it('has shouldUseFibers method', function () {
    $ref = new ReflectionMethod(FiberHandleRequests::class, 'shouldUseFibers');

    expect($ref->isProtected())->toBeTrue();
});

it('has processConcurrently method', function () {
    $ref = new ReflectionMethod(FiberHandleRequests::class, 'processConcurrently');

    expect($ref->getNumberOfParameters())->toBe(2)
        ->and($ref->isProtected())->toBeTrue();
});
