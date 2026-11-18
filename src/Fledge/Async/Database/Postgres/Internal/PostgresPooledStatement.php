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
    /**
     * @param \Closure():void $release
     * @param (\Closure():void)|null $awaitBusyResource
     * @param (\Closure():void)|null $onError Invoked when an execution fails (session tracking).
     */
    public function __construct(
        PostgresStatement $statement,
        \Closure $release,
        ?\Closure $awaitBusyResource = null,
        private readonly ?\Closure $onError = null,
    ) {
        parent::__construct($statement, $release, $awaitBusyResource);
    }

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
        try {
            return parent::execute($params);
        } catch (\Throwable $exception) {
            $this->onError && ($this->onError)();
            throw $exception;
        }
    }
}
