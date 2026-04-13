<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Database\SqlTransaction;

/**
 * @extends SqlTransaction<MysqlResult, MysqlStatement, MysqlTransaction>
 */
interface MysqlTransaction extends MysqlLink, SqlTransaction
{
}
