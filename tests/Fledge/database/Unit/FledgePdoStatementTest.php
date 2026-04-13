<?php

use Fledge\Async\Database\SqlStatement;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Tests\Fledge\database\Stubs\FakeRowResult;

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

    $mockStatement->shouldReceive('execute')
        ->once()
        ->with(['foo', 'bar'])
        ->andReturn(new FakeRowResult([]));

    $stmt = new FledgePdoStatement($mockStatement);
    $stmt->bindValue(1, 'foo', PDO::PARAM_STR);
    $stmt->bindValue(2, 'bar', PDO::PARAM_STR);

    expect($stmt->execute())->toBeTrue();
});

it('converts 1-based bindings to 0-based array', function () {
    $mockStatement = Mockery::mock(SqlStatement::class);

    $mockStatement->shouldReceive('execute')
        ->once()
        ->with(['first', 'second', 'third'])
        ->andReturn(new FakeRowResult([]));

    $stmt = new FledgePdoStatement($mockStatement);
    $stmt->bindValue(1, 'first', PDO::PARAM_STR);
    $stmt->bindValue(2, 'second', PDO::PARAM_STR);
    $stmt->bindValue(3, 'third', PDO::PARAM_STR);

    $stmt->execute();
});

it('clears bindings after execute', function () {
    $mockStatement = Mockery::mock(SqlStatement::class);
    $mockStatement->shouldReceive('execute')->andReturn(new FakeRowResult([]));

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

    $result = new FakeRowResult($rows);

    $stmt = new FledgePdoStatement(null, $result);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    $result = $stmt->fetchAll();

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBeObject()
        ->and($result[0]->id)->toBe(1)
        ->and($result[0]->name)->toBe('Alice');
});

it('fetches all rows in FETCH_ASSOC mode', function () {
    $rows = [['id' => 1, 'name' => 'Alice']];
    $result = new FakeRowResult($rows);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->fetchAll(PDO::FETCH_ASSOC))->toBe([['id' => 1, 'name' => 'Alice']]);
});

it('fetches all rows in FETCH_NUM mode', function () {
    $rows = [['id' => 1, 'name' => 'Alice']];
    $result = new FakeRowResult($rows);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->fetchAll(PDO::FETCH_NUM))->toBe([[1, 'Alice']]);
});

it('fetches all rows in FETCH_BOTH mode', function () {
    $rows = [['id' => 1, 'name' => 'Alice']];
    $result = new FakeRowResult($rows);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->fetchAll(PDO::FETCH_BOTH))->toBe([[1, 'Alice', 'id' => 1, 'name' => 'Alice']]);
});

it('fetches all rows in FETCH_COLUMN mode', function () {
    $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
    $result = new FakeRowResult($rows);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->fetchAll(PDO::FETCH_COLUMN))->toBe([1, 2, 3]);
});

it('fetches single row', function () {
    $result = new FakeRowResult([['id' => 1, 'name' => 'Alice']]);

    $stmt = new FledgePdoStatement(null, $result);
    $stmt->setFetchMode(PDO::FETCH_OBJ);

    $row = $stmt->fetch();

    expect($row)->toBeObject()
        ->and($row->id)->toBe(1);
});

it('returns false when no rows remain', function () {
    $result = new FakeRowResult([]);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->fetch())->toBeFalse();
});

it('returns row count', function () {
    $result = new FakeRowResult([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4], ['a' => 5]]);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->rowCount())->toBe(5);
});

it('returns zero row count without result', function () {
    expect((new FledgePdoStatement)->rowCount())->toBe(0);
});

it('advances to next rowset', function () {
    $result = new FakeRowResult([], new FakeRowResult([]));

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->nextRowset())->toBeTrue();
});

it('returns false when no next rowset', function () {
    $result = new FakeRowResult([]);

    $stmt = new FledgePdoStatement(null, $result);

    expect($stmt->nextRowset())->toBeFalse();
});

it('sets fetch mode', function () {
    $stmt = new FledgePdoStatement;

    expect($stmt->setFetchMode(PDO::FETCH_ASSOC))->toBeTrue();

    $ref = new ReflectionProperty($stmt, 'fetchMode');
    expect($ref->getValue($stmt))->toBe(PDO::FETCH_ASSOC);
});

it('fetches all rows in FETCH_CLASS mode', function () {
    $rows = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $result = new FakeRowResult($rows);

    $stmt = new FledgePdoStatement(null, $result);

    $result = $stmt->fetchAll(PDO::FETCH_CLASS, \stdClass::class);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBeObject()
        ->and($result[0]->id)->toBe(1)
        ->and($result[0]->name)->toBe('Alice')
        ->and($result[1]->name)->toBe('Bob');
});

it('stores named parameters via bindValue', function () {
    $stmt = new FledgePdoStatement;

    $stmt->bindValue(':name', 'Alice');
    $stmt->bindValue(':age', 30, PDO::PARAM_INT);

    $ref = new ReflectionProperty($stmt, 'bindings');
    $bindings = $ref->getValue($stmt);

    expect($bindings[':name'])->toBe('Alice')
        ->and($bindings[':age'])->toBe(30);
});

it('buildExecuteParams passes named params as-is', function () {
    $stmt = new FledgePdoStatement;

    $stmt->bindValue(':name', 'Alice');
    $stmt->bindValue(':age', '30');

    $method = new ReflectionMethod($stmt, 'buildExecuteParams');
    $params = $method->invoke($stmt);

    expect($params)->toBe([':name' => 'Alice', ':age' => '30']);
});

it('buildExecuteParams returns empty array when no bindings', function () {
    $stmt = new FledgePdoStatement;

    $method = new ReflectionMethod($stmt, 'buildExecuteParams');

    expect($method->invoke($stmt))->toBe([]);
});

it('setFetchMode persists across multiple fetchAll calls', function () {
    $rows1 = [['id' => 1]];
    $rows2 = [['id' => 2]];

    $result1 = new FakeRowResult($rows1);

    $stmt = new FledgePdoStatement(null, $result1);
    $stmt->setFetchMode(PDO::FETCH_ASSOC);

    $result = $stmt->fetchAll();

    expect($result)->toBe([['id' => 1]]);

    // Set new result and fetch again — mode should persist
    $result2 = new FakeRowResult($rows2);

    $resultProp = new ReflectionProperty($stmt, 'result');
    $resultProp->setValue($stmt, $result2);

    $fetched2 = $stmt->fetchAll();

    expect($fetched2)->toBe([['id' => 2]]);
});
