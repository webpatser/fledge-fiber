<?php

use Fledge\Async\Database\Mysql\Internal\MysqlStatementPool;
use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlStatementPool;

use function Fledge\Async\delay;

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

/**
 * A pool on the test server. The prepared statements read the session's own Com_stmt_* counters,
 * so each execute shows how many prepare, execute and reset round trips that connection took so far.
 */
function releaseTestMysqlPool(): MysqlConnectionPool
{
    $config = mysqlConfig();

    return new MysqlConnectionPool(new MysqlConfig(
        host: $config['host'],
        port: $config['port'],
        user: $config['username'],
        password: $config['password'],
        database: $config['database'],
    ));
}

/**
 * Execute, read the counters, and let the release callback put the statement back.
 *
 * @return array<string, int> Variable_name => Value
 */
function releaseTestCounters(SqlStatement $statement, array $params = []): array
{
    $result = $statement->execute($params);
    $counters = [];

    while (($row = $result->fetchRow()) !== null) {
        $counters[$row['Variable_name']] = (int) $row['Value'];
    }

    unset($result);
    delay(0);

    return $counters;
}

it('sends no COM_STMT_RESET when a pooled statement goes back without long data', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available');
    }

    $pool = releaseTestMysqlPool();

    try {
        $statement = $pool->prepare("SHOW SESSION STATUS WHERE Variable_name IN ('Com_stmt_prepare', 'Com_stmt_execute', 'Com_stmt_reset')");
        $runs = [];

        for ($i = 0; $i < 5; $i++) {
            $runs[] = releaseTestCounters($statement);
        }
    } finally {
        $pool->close();
    }

    // No reset between the executes (pdo_mysql sends none either), and one prepare: the same
    // retained statement on the same session each time. Com_stmt_execute is not compared, as the
    // server reports it with a lag inside a prepared SHOW STATUS.
    expect(array_column($runs, 'Com_stmt_reset'))->toBe([0, 0, 0, 0, 0])
        ->and(array_column($runs, 'Com_stmt_prepare'))->toBe([1, 1, 1, 1, 1])
        ->and(array_column($runs, 'Com_stmt_execute'))->not->toContain(0);
});

it('still resets a pooled statement that goes back holding long data', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available');
    }

    $pool = releaseTestMysqlPool();

    try {
        // Rows only when both parameters arrive as given: stale long data for parameter 0 would
        // replace the first value and shift the second.
        $statement = $pool->prepare("SHOW SESSION STATUS WHERE Variable_name = ? AND ? = 'go'");
        $before = releaseTestCounters($statement, ['Com_stmt_reset', 'go']);

        // The long data for parameter 0 reaches the server, the execute never does.
        $statement->bind(0, 'LONG');
        expect(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 1 missing');
        delay(0);

        $statement->reset();
        $after = releaseTestCounters($statement, ['Com_stmt_reset', 'go']);
    } finally {
        $pool->close();
    }

    // Exactly one reset, which dropped the stale long data.
    expect($before)->toBe(['Com_stmt_reset' => 0])
        ->and($after)->toBe(['Com_stmt_reset' => 1]);
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
