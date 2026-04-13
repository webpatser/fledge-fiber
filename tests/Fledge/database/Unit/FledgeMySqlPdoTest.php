<?php

use Fledge\Async\Database\Mysql\Internal\MysqlCommandResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Mysql\MysqlTransaction;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Tests\Fledge\database\Stubs\FakeRowResult;

afterEach(fn () => Mockery::close());

it('prepares and returns FledgePdoStatement', function () {
    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT * FROM users WHERE id = ?')
        ->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->prepare('SELECT * FROM users WHERE id = ?'))
        ->toBeInstanceOf(FledgePdoStatement::class);
});

it('exec returns affected row count', function () {
    $result = new MysqlCommandResult(3, 0);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')->once()->andReturn($result);

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->exec('DELETE FROM users WHERE active = 0'))->toBe(3);
});

it('tracks last insert ID from exec', function () {
    $result = new MysqlCommandResult(1, 42);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')->andReturn($result);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->exec("INSERT INTO users (name) VALUES ('test')");

    expect($pdo->lastInsertId())->toBe('42');
});

it('tracks last insert ID from prepared statement execute', function () {
    $result = new MysqlCommandResult(1, 99);

    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockStmt->shouldReceive('execute')
        ->once()
        ->with(['Alice'])
        ->andReturn($result);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);

    $stmt = $pdo->prepare("INSERT INTO users (name) VALUES (?)");
    $stmt->bindValue(1, 'Alice', PDO::PARAM_STR);
    $stmt->execute();

    expect($pdo->lastInsertId())->toBe('99');
});

it('pins queries to transaction connection', function () {
    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('prepare')
        ->once()
        ->with('INSERT INTO users (name) VALUES (?)')
        ->andReturn($mockStmt);
    $mockTransaction->shouldReceive('commit')->once();

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);
    $mockPool->shouldNotReceive('prepare');

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->beginTransaction())->toBeTrue()
        ->and($pdo->inTransaction())->toBeTrue();

    $pdo->prepare('INSERT INTO users (name) VALUES (?)');

    expect($pdo->commit())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse();
});

it('rolls back transaction', function () {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('rollback')->once();

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->andReturn($mockTransaction);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();

    expect($pdo->rollBack())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse();
});

it('returns false for rollback without transaction', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->rollBack())->toBeFalse();
});

it('caches server version from SELECT VERSION()', function () {
    $result = new FakeRowResult([['VERSION()' => '8.0.35']]);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')
        ->once()
        ->with('SELECT VERSION()')
        ->andReturn($result);

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toBe('8.0.35')
        ->and($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toBe('8.0.35'); // cached
});

it('returns mysql as driver name', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('mysql');
});

it('quotes strings with MySQL escaping', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->quote('hello'))->toBe("'hello'")
        ->and($pdo->quote("it's"))->toBe("'it\\'s'")
        ->and($pdo->quote("line\nbreak"))->toBe("'line\\nbreak'");
});

it('quotes integers with PARAM_INT', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->quote('42', PDO::PARAM_INT))->toBe('42')
        ->and($pdo->quote('not_a_number', PDO::PARAM_INT))->toBe('0');
});

it('uses pool after commit', function () {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('commit')->once();

    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->andReturn($mockTransaction);
    $mockPool->shouldReceive('prepare')->once()->with('SELECT 1')->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();
    $pdo->commit();

    $pdo->prepare('SELECT 1');
});
