<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

enum MysqlResultProxyState
{
    case Initial;
    case Fetched;
    case Complete;
}
