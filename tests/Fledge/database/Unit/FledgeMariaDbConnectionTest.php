<?php

use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;

it('extends MariaDbConnection', function () {
    expect(is_subclass_of(FledgeMariaDbConnection::class, \Illuminate\Database\MariaDbConnection::class))
        ->toBeTrue();
});

it('has prepared method', function () {
    $ref = new ReflectionClass(FledgeMariaDbConnection::class);

    expect($ref->hasMethod('prepared'))->toBeTrue();
    expect($ref->getMethod('prepared')->getDeclaringClass()->getName())
        ->toBe(FledgeMariaDbConnection::class);
});

it('has insert method', function () {
    $ref = new ReflectionClass(FledgeMariaDbConnection::class);

    expect($ref->hasMethod('insert'))->toBeTrue();
    expect($ref->getMethod('insert')->getDeclaringClass()->getName())
        ->toBe(FledgeMariaDbConnection::class);
});
