<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Mysql\MysqlTransaction;
use Fledge\Async\Database\SqlNestableTransactionExecutor;
use Fledge\Async\Database\SqlNestedTransaction;
use Fledge\Async\Database\SqlTransaction;

/**
 * @internal
 * @extends SqlNestedTransaction<MysqlResult, MysqlStatement, MysqlTransaction, MysqlNestableExecutor>
 */
final class MysqlNestedTransaction extends SqlNestedTransaction implements MysqlTransaction
{
    use MysqlTransactionDelegate;

    /**
     * @param non-empty-string $identifier
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly MysqlTransaction $transaction,
        MysqlNestableExecutor $executor,
        string $identifier,
        \Closure $release,
    ) {
        parent::__construct($transaction, $executor, $identifier, $release);
    }

    protected function getTransaction(): MysqlTransaction
    {
        return $this->transaction;
    }

    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): MysqlTransaction {
        return new self($transaction, $executor, $identifier, $release);
    }
}
