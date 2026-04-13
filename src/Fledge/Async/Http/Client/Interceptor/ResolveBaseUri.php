<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Request;
use League\Uri\Http;

final class ResolveBaseUri extends ModifyRequest
{
    public function __construct(string $baseUri)
    {
        parent::__construct(
            fn (Request $request) => $request->setUri(Http::fromBaseUri($request->getUri(), $baseUri))
        );
    }
}
