<?php declare(strict_types=1);

namespace Fledge\Async\File;

enum LockType
{
    case Shared;
    case Exclusive;
}
