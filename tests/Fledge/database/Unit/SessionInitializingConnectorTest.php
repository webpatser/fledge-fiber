<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Fiber\Database\Connectors\SessionInitializingConnector;
use Tests\Fledge\database\Stubs\FakeSqlConnector;

it('runs the session statements in order on every new connection', function () {
    $inner = new FakeSqlConnector;
    $connector = new SessionInitializingConnector($inner, [
        'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        "SET time_zone='+01:00'",
    ]);

    $connection = $connector->connect(new MysqlConfig('localhost'));

    expect($connection)->toBe($inner->lastConnection)
        ->and($inner->lastConnection->queries)->toBe([
            'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
            "SET time_zone='+01:00'",
        ]);

    $second = $connector->connect(new MysqlConfig('localhost'));

    expect($second)->not->toBe($connection)
        ->and($inner->lastConnection->queries)->toHaveCount(2);
});

it('delegates without statements', function () {
    $inner = new FakeSqlConnector;
    $connector = new SessionInitializingConnector($inner, []);

    $connection = $connector->connect(new MysqlConfig('localhost'));

    expect($connection)->toBe($inner->lastConnection)
        ->and($inner->lastConnection->queries)->toBe([]);
});

it('closes the connection and throws when a statement fails', function () {
    $inner = new FakeSqlConnector(failOn: 'SET broken');
    $connector = new SessionInitializingConnector($inner, [
        "SET time_zone='+01:00'",
        'SET broken',
    ]);

    try {
        $connector->connect(new MysqlConfig('localhost'));

        $this->fail('Expected SqlConnectionException was not thrown');
    } catch (SqlConnectionException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($inner->lastConnection->closed)->toBeTrue()
            ->and($inner->lastConnection->queries)->toBe(["SET time_zone='+01:00'"]);
    }
});
