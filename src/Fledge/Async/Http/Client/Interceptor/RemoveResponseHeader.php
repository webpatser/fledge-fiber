<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Response;

final class RemoveResponseHeader extends ModifyResponse
{
    public function __construct(string $headerName)
    {
        parent::__construct(static function (Response $response) use ($headerName) {
            $response->removeHeader($headerName);

            return $response;
        });
    }
}
