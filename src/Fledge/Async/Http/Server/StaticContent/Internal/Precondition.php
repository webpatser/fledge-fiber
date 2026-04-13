<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\StaticContent\Internal;

/** @internal */
enum Precondition
{
    case NotModified;
    case Failed;
    case IfRangeOk;
    case IfRangeFailed;
    case Ok;
}
