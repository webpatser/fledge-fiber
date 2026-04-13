<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\SqlPooledStatement;
use Fledge\Async\Database\SqlResult;

/**
 * @internal
 * @extends SqlPooledStatement<PostgresResult, PostgresStatement>
 */
final class PostgresPooledStatement extends SqlPooledStatement implements PostgresStatement
{
    #[\Override]
    protected function createResult(SqlResult $result, \Closure $release): PostgresResult
    {
        \assert($result instanceof PostgresResult);
        return new PostgresPooledResult($result, $release);
    }

    /**
     * Changes return type to this library's Result type.
     */
    #[\Override]
    public function execute(array $params = []): PostgresResult
    {
        return parent::execute($params);
    }
}
