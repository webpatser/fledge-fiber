<?php

use Fledge\Async\Database\Mysql\Internal\MysqlStatementPool;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlStatementPool;

/*
 * Statement release runs from event-loop callbacks (SqlPooledResult queues it). An exception
 * there surfaces as a Revolt UncaughtThrowable in the next, unrelated query, so a release on a
 * connection that was killed or timed out (KILL, wait_timeout) must discard the statement quietly.
 */
afterEach(fn () => Mockery::close());

function releaseTestPool(): SqlConnectionPool
{
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('getConnectionLimit')->andReturn(10);
    $pool->shouldReceive('getConnectionCount')->andReturn(1);
    $pool->shouldReceive('getIdleConnectionCount')->andReturn(1);
    $pool->shouldReceive('getIdleTimeout')->andReturn(60);

    return $pool;
}

it('discards a MySQL statement whose reset fails on a dead connection', function () {
    $dead = Mockery::mock(MysqlStatement::class);
    $dead->shouldReceive('isClosed')->andReturn(false);
    $dead->shouldReceive('reset')->once()->andThrow(new SqlConnectionException('Connection closed unexpectedly'));
    $dead->shouldReceive('close')->once();

    $fresh = Mockery::mock(MysqlStatement::class);
    $fresh->shouldReceive('isClosed')->andReturn(false);

    $statementPool = new MysqlStatementPool(releaseTestPool(), 'SELECT 1', fn () => $fresh);

    (new ReflectionMethod($statementPool, 'push'))->invoke($statementPool, $dead);

    // The dead statement was not retained: the next pop prepares a fresh one.
    expect((new ReflectionMethod($statementPool, 'pop'))->invoke($statementPool))->toBe($fresh);

    $statementPool->close();
});

it('never lets the release callback throw', function () {
    $statement = Mockery::mock(SqlStatement::class);
    $statement->shouldReceive('isClosed')->andReturn(false);
    $statement->shouldReceive('execute')->andReturn(Mockery::mock(SqlResult::class));
    $statement->shouldReceive('close')->once()->andThrow(new SqlConnectionException('Connection went away'));

    $statementPool = new class(releaseTestPool(), 'SELECT 1', fn () => $statement) extends SqlStatementPool
    {
        public ?Closure $release = null;

        protected function push(SqlStatement $statement): void
        {
            throw new SqlConnectionException('Connection closed unexpectedly');
        }

        protected function createResult(SqlResult $result, Closure $release): SqlResult
        {
            $this->release = $release;

            return $result;
        }
    };

    $statementPool->execute();

    expect(fn () => ($statementPool->release)())->not->toThrow(Throwable::class);

    $statementPool->close();
});
