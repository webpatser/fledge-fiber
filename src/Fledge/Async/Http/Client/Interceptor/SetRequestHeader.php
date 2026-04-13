<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Request;

final class SetRequestHeader extends ModifyRequest
{
    /**
     * @param non-empty-string $headerName
     */
    public function __construct(string $headerName, string $headerValue, string ...$headerValues)
    {
        \array_unshift($headerValues, $headerValue);

        parent::__construct(static function (Request $request) use ($headerName, $headerValues) {
            $request->setHeader($headerName, $headerValues);

            return $request;
        });
    }
}
