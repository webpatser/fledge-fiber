<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Mysql\MysqlTransaction;
use Fledge\Async\Database\SqlPooledTransaction;
use Fledge\Async\Database\SqlTransaction;

/**
 * @internal
 * @extends SqlPooledTransaction<MysqlResult, MysqlStatement, MysqlTransaction>
 */
final class MysqlPooledTransaction extends SqlPooledTransaction implements MysqlTransaction
{
    use MysqlTransactionDelegate;

    /**
     * @param \Closure():void $release
     */
    public function __construct(private readonly MysqlTransaction $transaction, \Closure $release)
    {
        parent::__construct($transaction, $release);
    }

    protected function createTransaction(SqlTransaction $transaction, \Closure $release): MysqlTransaction
    {
        \assert($transaction instanceof MysqlTransaction);
        return new MysqlPooledTransaction($transaction, $release);
    }
}
