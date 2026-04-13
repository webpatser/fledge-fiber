<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\Postgres\PostgresTransaction;
use Fledge\Async\Database\SqlStatementPool as SqlStatementPool;
use Fledge\Async\Database\SqlResult as SqlResult;

/**
 * @internal
 * @extends SqlStatementPool<PostgresConfig, PostgresResult, PostgresStatement, PostgresTransaction>
 */
final class PostgresStatementPool extends SqlStatementPool implements PostgresStatement
{
    #[\Override]
    protected function createResult(SqlResult $result, \Closure $release): PostgresResult
    {
        \assert($result instanceof PostgresResult);
        return new PostgresPooledResult($result, $release);
    }

    #[\Override]
    public function execute(array $params = []): PostgresResult
    {
        return parent::execute($params);
    }
}
