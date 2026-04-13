<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Stream;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnector;

/**
 * @implements SqlConnector<MysqlConfig, MysqlConnection>
 */
final class SocketMysqlConnector implements SqlConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(private readonly ?Stream\SocketConnector $connector = null)
    {
    }

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): MysqlConnection
    {
        if (!$config instanceof MysqlConfig) {
            throw new \TypeError(\sprintf("Must provide an instance of %s to MySQL connectors", MysqlConfig::class));
        }

        $connector = $this->connector ?? Stream\socketConnector();

        return SocketMysqlConnection::connect($connector, $config, $cancellation);
    }
}
