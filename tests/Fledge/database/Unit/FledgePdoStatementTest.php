<?php

use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;

beforeEach(function () {
    //
});

afterEach(function () {
    Mockery::close();
});

it('collects bound values', function () {
    $stmt = new FledgePdoStatement;

    $stmt->bindValue(1, 'foo', PDO::PARAM_STR);
    $stmt->bindValue(2, 42, PDO::PARAM_INT);
    $stmt->bindValue(3, null, PDO::PARAM_NULL);

    $ref = new ReflectionProperty($stmt, 'bindings');
    $bindings = $ref->getValue($stmt);

    expect($bindings[1])->toBe('foo')
        ->and($bindings[2])->toBe(42)
        ->and($bindings[3])->toBeNull();
});

it('passes params to statement on execute', function () {
    $mockStatement = Mockery::mock(SqlStatement::class);
    $mockResult = Mockery::mock(SqlResult::class);

    $mockStatement->shouldReceive('execute')
        ->once()
        ->with(['foo', 'bar'])
        ->andReturn($mockResult);

    $stmt = new FledgePdoStatement($mockStatement);
    $stmt->bindValue(1, 'foo', PDO::PARAM_STR);
    $stmt->bindValue(2, 'bar', PDO::PARAM_STR);

    expect($stmt->execute())->toBeTrue();
});

it('converts 1-based bindings to 0-based array', function () {
    $mockStatement = Mockery::mock(SqlStatement::class);
    $mockResult = Mockery::mock(SqlResult::class);

    $mockStatement->shouldReceive('execute')
        ->once()
        ->with(['first', 'second', 'third'])
        ->andReturn($mockResult);

    $stmt = new FledgePdoStatement($mockStatement);
    $stmt->bindValue(1, 'first', PDO::PARAM_STR);
    $stmt->bindValue(2, 'second', PDO::PARAM_STR);
    $stmt->bindValue(3, 'third', PDO::PARAM_STR);

    $stmt->execute();
});

it('clears bindings after execute', function () {
    $mockStatement = Mockery::mock(SqlStatement::class);
    $mockResult = Mockery::mock(SqlResult::class);
    $mockStatement->shouldReceive('execute')->andReturn($mockResult);

    $stmt = new FledgePdoStatement($mockStatement);
    $stmt->bindValue(1, 'foo', PDO::PARAM_STR);
    $stmt->execute();

    $ref = new ReflectionProperty($stmt, 'bindings');
    expect($ref->getValue($stmt))->toBeEmpty();
});

it('fetches all rows as objects by default', function () {
    $rows = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $mockResult = Mockery::mock(SqlResult::class, IteratorAggregate::class);
    $mockResult->shouldReceive('getIterator')->andReturn(new ArrayIterator($rows));

    $stmt = new FledgePdoStatement(null, $mockResult);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    $result = $stmt->fetchAll();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBeObject()
        ->and($result[0]->id)->toBe(1)
        ->and($result[0]->name)->toBe('Alice');
});

it('fetches all rows in FETCH_ASSOC mode', function () {
    $rows = [['id' => 1, 'name' => 'Alice']];
    $mockResult = Mockery::mock(SqlResult::class, IteratorAggregate::class);
    $mockResult->shouldReceive('getIterator')->andReturn(new ArrayIterator($rows));

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->fetchAll(PDO::FETCH_ASSOC))->toBe([['id' => 1, 'name' => 'Alice']]);
});

it('fetches all rows in FETCH_NUM mode', function () {
    $rows = [['id' => 1, 'name' => 'Alice']];
    $mockResult = Mockery::mock(SqlResult::class, IteratorAggregate::class);
    $mockResult->shouldReceive('getIterator')->andReturn(new ArrayIterator($rows));

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->fetchAll(PDO::FETCH_NUM))->toBe([[1, 'Alice']]);
});

it('fetches all rows in FETCH_BOTH mode', function () {
    $rows = [['id' => 1, 'name' => 'Alice']];
    $mockResult = Mockery::mock(SqlResult::class, IteratorAggregate::class);
    $mockResult->shouldReceive('getIterator')->andReturn(new ArrayIterator($rows));

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->fetchAll(PDO::FETCH_BOTH))->toBe([[1, 'Alice', 'id' => 1, 'name' => 'Alice']]);
});

it('fetches all rows in FETCH_COLUMN mode', function () {
    $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
    $mockResult = Mockery::mock(SqlResult::class, IteratorAggregate::class);
    $mockResult->shouldReceive('getIterator')->andReturn(new ArrayIterator($rows));

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->fetchAll(PDO::FETCH_COLUMN))->toBe([1, 2, 3]);
});

it('fetches single row', function () {
    $mockResult = Mockery::mock(SqlResult::class);
    $mockResult->shouldReceive('fetchRow')->once()->andReturn(['id' => 1, 'name' => 'Alice']);

    $stmt = new FledgePdoStatement(null, $mockResult);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    $row = $stmt->fetch();

    expect($row)->toBeObject()
        ->and($row->id)->toBe(1);
});

it('returns false when no rows remain', function () {
    $mockResult = Mockery::mock(SqlResult::class);
    $mockResult->shouldReceive('fetchRow')->once()->andReturn(null);

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->fetch())->toBeFalse();
});

it('returns row count', function () {
    $mockResult = Mockery::mock(SqlResult::class);
    $mockResult->shouldReceive('getRowCount')->once()->andReturn(5);

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->rowCount())->toBe(5);
});

it('returns zero row count without result', function () {
    expect((new FledgePdoStatement)->rowCount())->toBe(0);
});

it('advances to next rowset', function () {
    $nextResult = Mockery::mock(SqlResult::class);
    $mockResult = Mockery::mock(SqlResult::class);
    $mockResult->shouldReceive('getNextResult')->once()->andReturn($nextResult);

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->nextRowset())->toBeTrue();
});

it('returns false when no next rowset', function () {
    $mockResult = Mockery::mock(SqlResult::class);
    $mockResult->shouldReceive('getNextResult')->once()->andReturn(null);

    $stmt = new FledgePdoStatement(null, $mockResult);

    expect($stmt->nextRowset())->toBeFalse();
});

it('sets fetch mode', function () {
    $stmt = new FledgePdoStatement;

    expect($stmt->setFetchMode(PDO::FETCH_ASSOC))->toBeTrue();

    $ref = new ReflectionProperty($stmt, 'fetchMode');
    expect($ref->getValue($stmt))->toBe(PDO::FETCH_ASSOC);
});
