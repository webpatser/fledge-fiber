<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Database\SqlConnection;

/**
 * @extends SqlConnection<MysqlConfig, MysqlResult, MysqlStatement, MysqlTransaction>
 */
interface MysqlConnection extends MysqlLink, SqlConnection
{
    /**
     * @return MysqlConfig Config object specific to this library.
     */
    public function getConfig(): MysqlConfig;
}
