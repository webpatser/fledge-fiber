<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Mysql\MysqlTransaction;
use Fledge\Async\Database\SqlStatementPool;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;

/**
 * @internal
 * @extends SqlStatementPool<MysqlConfig, MysqlResult, MysqlStatement, MysqlTransaction>
 */
final class MysqlStatementPool extends SqlStatementPool implements MysqlStatement
{
    private array $params = [];

    protected function pop(): MysqlStatement
    {
        $statement = parent::pop();

        try {
            \assert($statement instanceof MysqlStatement);

            foreach ($this->params as $paramId => $data) {
                $statement->bind($paramId, $data);
            }
        } catch (\Throwable $exception) {
            $this->push($statement);
            throw $exception;
        }

        return $statement;
    }

    protected function push(SqlStatement $statement): void
    {
        if ($statement->isClosed()) {
            return;
        }

        // push() runs from release callbacks queued on the event loop, where an exception
        // would surface as an UncaughtThrowable in an unrelated query. A reset that fails
        // means the connection is gone (KILL, wait_timeout): discard the statement instead.
        try {
            $statement->reset();
        } catch (\Throwable) {
            try {
                $statement->close();
            } catch (\Throwable) {
                // The connection is already dead; nothing left to release.
            }

            return;
        }

        parent::push($statement);
    }

    protected function createResult(SqlResult $result, \Closure $release): MysqlResult
    {
        if (!$result instanceof MysqlResult) {
            throw new \TypeError('Result object must be an instance of ' . MysqlResult::class);
        }

        return new MysqlPooledResult($result, $release);
    }

    public function execute(array $params = []): MysqlResult
    {
        return parent::execute($params);
    }

    public function bind(int|string $paramId, string $data): void
    {
        $prior = $this->params[$paramId] ?? '';
        $this->params[$paramId] = $prior . $data;
    }

    public function reset(): void
    {
        $this->params = [];
    }

    public function getColumnDefinitions(): array
    {
        $statement = parent::pop();
        $columns = $statement->getColumnDefinitions();
        parent::push($statement);
        return $columns;
    }

    public function getParameterDefinitions(): array
    {
        $statement = parent::pop();
        $parameters = $statement->getParameterDefinitions();
        parent::push($statement);
        return $parameters;
    }
}
