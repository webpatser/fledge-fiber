<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresExecutor;
use Fledge\Async\Database\Postgres\PostgresListener;
use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\SqlNestableTransactionExecutor;

/**
 * @internal
 * @extends SqlNestableTransactionExecutor<PostgresResult, PostgresStatement>
 */
interface PostgresHandle extends PostgresExecutor, SqlNestableTransactionExecutor
{
    public const STATEMENT_NAME_PREFIX = "fledge_";

    public function getConfig(): PostgresConfig;

    /**
     * @param non-empty-string $channel
     */
    public function listen(string $channel): PostgresListener;

    /**
     * Execute the statement with the given name and parameters.
     *
     * @param list<mixed> $params List of statement parameters, indexed starting at 0.
     */
    public function statementExecute(string $name, array $params): PostgresResult;

    /**
     * Deallocate the statement with the given name.
     */
    public function statementDeallocate(string $name): void;
}
