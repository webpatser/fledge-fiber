<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Mysql\MysqlTransaction;
use Fledge\Async\Database\SqlConnectionTransaction;
use Fledge\Async\Database\SqlNestableTransactionExecutor;
use Fledge\Async\Database\SqlTransaction;

/**
 * @internal
 * @extends SqlConnectionTransaction<MysqlResult, MysqlStatement, MysqlTransaction, MysqlNestableExecutor>
 */
final class MysqlConnectionTransaction extends SqlConnectionTransaction implements MysqlTransaction
{
    use MysqlTransactionDelegate;

    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): MysqlTransaction {
        \assert($transaction instanceof MysqlTransaction);
        return new MysqlNestedTransaction($transaction, $executor, $identifier, $release);
    }
}
