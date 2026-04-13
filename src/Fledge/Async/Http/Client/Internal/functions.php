<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Internal;

use Fledge\Async\Http\Client\InvalidRequestException;
use Fledge\Async\Http\Client\Request;

/**
 * @throws InvalidRequestException
 *
 * @internal
 */
function normalizeRequestPathWithQuery(Request $request): string
{
    $path = $request->getUri()->getPath();
    $query = $request->getUri()->getQuery();

    if ($path === '') {
        return '/' . ($query !== '' ? '?' . $query : '');
    }

    if ($path[0] !== '/') {
        throw new InvalidRequestException(
            $request,
            'Relative path (' . $path . ') is not allowed in the request URI'
        );
    }

    return $path . ($query !== '' ? '?' . $query : '');
}
