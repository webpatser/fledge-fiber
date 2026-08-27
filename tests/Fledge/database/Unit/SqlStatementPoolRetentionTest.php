<?php

use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlStatementPool;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Async\Database\SqlTransactionIsolation;

/**
 * Regression tests for the statement pool retention guards: when push() declines
 * to retain a statement (saturated pool or per-statement cap), the statement must
 * be closed and the release closure must drop its reference, otherwise the
 * underlying connection stays checked out forever and a saturated pool deadlocks
 * on the next execute().
 */
function makeStatementStub(): SqlStatement
{
    return new class implements SqlStatement
    {
        public int $closed = 0;

        public function execute(array $params = []): SqlResult
        {
            throw new BadMethodCallException('not used');
        }

        public function getQuery(): string
        {
            return 'SELECT 1';
        }

        public function isClosed(): bool
        {
            return $this->closed > 0;
        }

        public function close(): void
        {
            $this->closed++;
        }

        public function onClose(\Closure $onClose): void
        {
        }

        public function getLastUsedAt(): int
        {
            return time();
        }
    };
}

function makePoolStub(int $limit, int $count, int $idle): SqlConnectionPool
{
    return new class($limit, $count, $idle) implements SqlConnectionPool
    {
        public function __construct(
            private int $limit,
            private int $count,
            private int $idle,
        ) {
        }

        public function getConnectionLimit(): int
        {
            return $this->limit;
        }

        public function getConnectionCount(): int
        {
            return $this->count;
        }

        public function getIdleConnectionCount(): int
        {
            return $this->idle;
        }

        public function getIdleTimeout(): int
        {
            return 60;
        }

        public function extractConnection(): SqlConnection
        {
            throw new BadMethodCallException('not used');
        }

        public function getConfig(): SqlConfig
        {
            throw new BadMethodCallException('not used');
        }

        public function getTransactionIsolation(): SqlTransactionIsolation
        {
            throw new BadMethodCallException('not used');
        }

        public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
        {
        }

        public function query(string $sql): SqlResult
        {
            throw new BadMethodCallException('not used');
        }

        public function prepare(string $sql): SqlStatement
        {
            throw new BadMethodCallException('not used');
        }

        public function execute(string $sql, array $params = []): SqlResult
        {
            throw new BadMethodCallException('not used');
        }

        public function beginTransaction(): SqlTransaction
        {
            throw new BadMethodCallException('not used');
        }

        public function isClosed(): bool
        {
            return false;
        }

        public function close(): void
        {
        }

        public function onClose(\Closure $onClose): void
        {
        }

        public function getLastUsedAt(): int
        {
            return time();
        }
    };
}

function makeStatementPool(SqlConnectionPool $pool): SqlStatementPool
{
    return new class($pool, 'SELECT 1', fn () => makeStatementStub()) extends SqlStatementPool
    {
        protected function createResult(SqlResult $result, \Closure $release): SqlResult
        {
            throw new BadMethodCallException('not used');
        }
    };
}

it('closes the statement when the pool is saturated and retention is declined', function () {
    // limit 1, all connections in use, none idle: the saturation guard declines.
    $statementPool = makeStatementPool(makePoolStub(limit: 1, count: 1, idle: 0));

    $statement = makeStatementStub();

    $push = new ReflectionMethod($statementPool, 'push');
    $push->invoke($statementPool, $statement);

    expect($statement->closed)->toBe(1);
});

it('closes the statement when the per-statement cap declines retention', function () {
    // limit 5: the cap allows floor(5/10) = 0 queued statements before declining,
    // so a first retained statement is enqueued and a second is declined.
    $statementPool = makeStatementPool(makePoolStub(limit: 5, count: 1, idle: 4));

    $push = new ReflectionMethod($statementPool, 'push');

    $first = makeStatementStub();
    $push->invoke($statementPool, $first);
    expect($first->closed)->toBe(0);

    $second = makeStatementStub();
    $push->invoke($statementPool, $second);
    expect($second->closed)->toBe(1);
});

it('retains the statement when the pool has capacity', function () {
    $statementPool = makeStatementPool(makePoolStub(limit: 20, count: 1, idle: 4));

    $statement = makeStatementStub();

    $push = new ReflectionMethod($statementPool, 'push');
    $push->invoke($statementPool, $statement);

    expect($statement->closed)->toBe(0);

    $pop = new ReflectionMethod($statementPool, 'pop');
    expect($pop->invoke($statementPool))->toBe($statement);
});
