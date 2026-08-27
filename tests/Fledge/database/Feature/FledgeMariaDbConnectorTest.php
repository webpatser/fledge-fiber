<?php

use Fledge\Fiber\Database\Connectors\FledgeMariaDbConnector;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;

it('extends the MySQL connector', function () {
    expect(new FledgeMariaDbConnector)->toBeInstanceOf(FledgeMySqlConnector::class);
});

it('never includes NO_AUTO_CREATE_USER in strict mode', function () {
    $connector = new FledgeMariaDbConnector;
    $method = new ReflectionMethod($connector, 'getSqlMode');

    $strict = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    expect($method->invoke($connector, ['strict' => true]))->toBe($strict)
        ->and($method->invoke($connector, ['strict' => true, 'version' => '5.7.44']))->toBe($strict)
        ->and($method->invoke($connector, ['strict' => true, 'version' => '10.11.6']))->toBe($strict);
});

it('returns non-strict and custom modes like the MySQL connector', function () {
    $connector = new FledgeMariaDbConnector;
    $method = new ReflectionMethod($connector, 'getSqlMode');

    expect($method->invoke($connector, ['strict' => false]))->toBe('NO_ENGINE_SUBSTITUTION')
        ->and($method->invoke($connector, ['modes' => ['STRICT_TRANS_TABLES', 'NO_ZERO_DATE']]))->toBe('STRICT_TRANS_TABLES,NO_ZERO_DATE')
        ->and($method->invoke($connector, []))->toBeNull();
});
