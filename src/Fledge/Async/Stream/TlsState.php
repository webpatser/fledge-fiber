<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

enum TlsState
{
    case Disabled;
    case SetupPending;
    case Enabled;
    case ShutdownPending;
}
