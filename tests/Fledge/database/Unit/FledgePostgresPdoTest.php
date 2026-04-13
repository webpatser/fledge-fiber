<?php

use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlStatement;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;
use Tests\Fledge\database\Stubs\FakeRowResult;

afterEach(fn () => Mockery::close());

it('converts ? placeholders to $N', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT * FROM users WHERE id = $1 AND name = $2')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('SELECT * FROM users WHERE id = ? AND name = ?');
});

it('preserves ? inside single-quoted strings', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with("SELECT * FROM users WHERE name = 'what?' AND id = \$1")
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare("SELECT * FROM users WHERE name = 'what?' AND id = ?");
});

it('preserves ? inside double-quoted identifiers', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT "col?" FROM users WHERE id = $1')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('SELECT "col?" FROM users WHERE id = ?');
});

it('handles queries without placeholders', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT * FROM users')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('SELECT * FROM users');
});

it('converts many placeholders', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('INSERT INTO t (a, b, c) VALUES ($1, $2, $3)')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('INSERT INTO t (a, b, c) VALUES (?, ?, ?)');
});

it('quotes strings with PostgreSQL escaping', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    expect($pdo->quote('hello'))->toBe("'hello'")
        ->and($pdo->quote("it's"))->toBe("'it''s'")
        ->and($pdo->quote("O'Brien"))->toBe("'O''Brien'");
});

it('returns pgsql as driver name', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('pgsql');
});

it('caches server version', function () {
    $result = new FakeRowResult([['version' => 'PostgreSQL 16.1 on x86_64']]);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')
        ->once()
        ->with('SELECT version()')
        ->andReturn($result);

    $pdo = new FledgePostgresPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))
        ->toBe('PostgreSQL 16.1 on x86_64');
});

it('preserves ? inside escaped single quotes', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with("SELECT * FROM t WHERE name = 'it''s a ?' AND id = \$1")
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare("SELECT * FROM t WHERE name = 'it''s a ?' AND id = ?");
});

it('convertPlaceholders handles empty string', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');

    expect($method->invoke($pdo, ''))->toBe('');
});

it('convertPlaceholders handles consecutive placeholders', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');

    expect($method->invoke($pdo, '(?, ?, ?)'))->toBe('($1, $2, $3)');
});

it('convertPlaceholders passes through query without ? or quotes', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');
    $query = 'SELECT id, name FROM users ORDER BY id';

    expect($method->invoke($pdo, $query))->toBe($query);
});
