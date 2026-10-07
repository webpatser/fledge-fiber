<?php

use Fledge\Async\Database\Postgres\PostgresQueryError;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Database\SqlStatement;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Fledge\Fiber\Database\Pdo\FledgePdoException;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;
use Illuminate\Database\ConcurrencyErrorDetector;

afterEach(fn () => Mockery::close());

function mysqlQueryError(int $code, string $state, string $message): SqlQueryError
{
    return new SqlQueryError(
        sprintf('MySQL error (%d): #%s %s', $code, $state, $message),
        'INSERT INTO t VALUES (1)',
        errorCode: $code,
        sqlState: $state,
        serverMessage: $message,
    );
}

it('keeps SqlQueryError backward compatible', function () {
    $error = new SqlQueryError('boom', 'SELECT 1');

    expect($error->getMessage())->toBe('boom')
        ->and($error->getQuery())->toBe('SELECT 1')
        ->and($error->getErrorCode())->toBe(0)
        ->and($error->getSqlState())->toBeNull()
        ->and($error->getServerMessage())->toBe('boom');
});

it('shapes a duplicate key error like pdo_mysql', function () {
    $error = mysqlQueryError(1062, '23000', "Duplicate entry 'a@b.c' for key 'users_email_unique'");

    $e = FledgePdoException::fromQueryError($error);

    expect($e)->toBeInstanceOf(PDOException::class)
        ->and($e->getMessage())->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.c' for key 'users_email_unique'")
        ->and($e->getCode())->toBe('23000')
        ->and($e->errorInfo)->toBe(['23000', 1062, "Duplicate entry 'a@b.c' for key 'users_email_unique'"])
        ->and($e->getPrevious())->toBe($error);
});

it('maps a deadlock to code 40001 that Laravel detects as a concurrency error', function () {
    $error = mysqlQueryError(1213, '40001', 'Deadlock found when trying to get lock; try restarting transaction');

    $e = FledgePdoException::fromQueryError($error);

    expect($e->getCode())->toBe('40001')
        ->and($e->getMessage())->toBe('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
        ->and($e->errorInfo)->toBe(['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'])
        ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($e))->toBeTrue();
});

it('describes SQLSTATEs like PDO', function (string $state, string $description) {
    expect(FledgePdoException::describe($state))->toBe($description);
})->with([
    ['23000', 'Integrity constraint violation'],
    ['23505', 'Unique violation'],
    ['40001', 'Serialization failure'],
    ['40P01', 'Deadlock detected'],
    ['42000', 'Syntax error or access violation'],
    ['42S02', 'Base table or view not found'],
    ['HY000', 'General error'],
    ['08S01', 'General error'],
]);

it('falls back to HY000 when the server reported no SQLSTATE', function () {
    $e = FledgePdoException::fromQueryError(new SqlQueryError('Empty query string'));

    expect($e->getCode())->toBe('HY000')
        ->and($e->getMessage())->toBe('SQLSTATE[HY000]: General error: 0 Empty query string');
});

it('rethrows statement execute errors as PDOException', function () {
    $error = mysqlQueryError(1062, '23000', "Duplicate entry '1' for key 'PRIMARY'");
    $statement = Mockery::mock(SqlStatement::class);
    $statement->shouldReceive('execute')->once()->andThrow($error);

    $stmt = new FledgePdoStatement($statement, pdo: new FledgeMySqlPdo(Mockery::mock(SqlConnectionPool::class)));

    try {
        $stmt->execute();
        $this->fail('Expected a PDOException');
    } catch (PDOException $e) {
        expect($e->getCode())->toBe('23000')
            ->and($e->getPrevious())->toBe($error);
    }
});

it('rethrows exec and prepare errors as PDOException', function () {
    $error = mysqlQueryError(1064, '42000', 'You have an error in your SQL syntax');
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('query')->once()->andThrow($error);
    $pool->shouldReceive('prepare')->once()->andThrow($error);

    $pdo = new FledgeMySqlPdo($pool);

    expect(fn () => $pdo->exec('SELEC 1'))->toThrow(PDOException::class, 'SQLSTATE[42000]: Syntax error or access violation: 1064')
        ->and(fn () => $pdo->prepare('SELEC 1'))->toThrow(PDOException::class, 'SQLSTATE[42000]');
});

it('shapes a postgres unique violation like pdo_pgsql', function () {
    $message = "ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(a@b.c) already exists.\n";
    $error = new PostgresQueryError($message, ['sqlstate' => '23505'], 'INSERT INTO users ...');

    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('prepare')->once()->andThrow($error);

    try {
        (new FledgePostgresPdo($pool))->prepare('INSERT INTO users (email) VALUES (?)');
        $this->fail('Expected a PDOException');
    } catch (PDOException $e) {
        expect($error->getSqlState())->toBe('23505')
            ->and($e->getCode())->toBe('23505')
            ->and($e->getMessage())->toStartWith('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_unique"')
            ->and($e->getMessage())->toContain('Key (email)=(a@b.c)')
            ->and($e->errorInfo[0])->toBe('23505')
            ->and($e->errorInfo[1])->toBe(7);
    }
});
