<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

enum SocketAddressType
{
    case Internet;
    case Unix;
}
