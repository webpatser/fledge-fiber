<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Request;

final class RemoveRequestHeader extends ModifyRequest
{
    public function __construct(string $headerName)
    {
        parent::__construct(static function (Request $request) use ($headerName) {
            $request->removeHeader($headerName);

            return $request;
        });
    }
}
