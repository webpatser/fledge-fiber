<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresExecutor;
use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\Postgres\PostgresTransaction;
use Fledge\Async\Database\SqlPooledTransaction;
use Fledge\Async\Database\SqlTransaction;

/**
 * @internal
 * @extends SqlPooledTransaction<PostgresResult, PostgresStatement, PostgresTransaction>
 */
final class PostgresPooledTransaction extends SqlPooledTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    /**
     * @param \Closure():void $release
     */
    public function __construct(private readonly PostgresTransaction $transaction, \Closure $release)
    {
        parent::__construct($transaction, $release);
    }

    #[\Override]
    protected function getExecutor(): PostgresExecutor
    {
        return $this->transaction;
    }

    #[\Override]
    protected function createTransaction(SqlTransaction $transaction, \Closure $release): PostgresTransaction
    {
        \assert($transaction instanceof PostgresTransaction);
        return new PostgresPooledTransaction($transaction, $release);
    }
}
