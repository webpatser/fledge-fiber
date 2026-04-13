<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Database\SqlLink;

/**
 * @extends SqlLink<MysqlResult, MysqlStatement, MysqlTransaction>
 */
interface MysqlLink extends MysqlExecutor, SqlLink
{
    /**
     * @return MysqlTransaction Transaction object specific to this library.
     */
    public function beginTransaction(): MysqlTransaction;
}
