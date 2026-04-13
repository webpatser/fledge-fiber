<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresExecutor;
use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\Postgres\PostgresTransaction;
use Fledge\Async\Database\SqlNestableTransactionExecutor;
use Fledge\Async\Database\SqlNestedTransaction;
use Fledge\Async\Database\SqlTransaction;

/**
 * @internal
 * @extends SqlNestedTransaction<PostgresResult, PostgresStatement, PostgresTransaction, PostgresHandle>
 */
final class PostgresNestedTransaction extends SqlNestedTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    /**
     * @param non-empty-string $identifier
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly PostgresTransaction $transaction,
        PostgresHandle $handle,
        string $identifier,
        \Closure $release,
    ) {
        parent::__construct($transaction, $handle, $identifier, $release);
    }

    #[\Override]
    protected function getExecutor(): PostgresExecutor
    {
        return $this->transaction;
    }

    #[\Override]
    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): PostgresTransaction {
        return new self($transaction, $executor, $identifier, $release);
    }

    #[\Override]
    public function prepare(string $sql): PostgresStatement
    {
        $statement = parent::prepare($sql);

        // Defer statement deallocation until parent is committed or rolled back.
        $this->transaction->onClose(static fn () => $statement);

        return $statement;
    }
}
