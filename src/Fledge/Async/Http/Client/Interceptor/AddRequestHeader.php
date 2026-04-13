<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Request;

final class AddRequestHeader extends ModifyRequest
{
    /**
     * @param non-empty-string $headerName
     */
    public function __construct(string $headerName, string ...$headerValues)
    {
        parent::__construct(static function (Request $request) use ($headerName, $headerValues) {
            $request->addHeader($headerName, $headerValues);

            return $request;
        });
    }
}
