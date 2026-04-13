<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\ApplicationInterceptor;
use Fledge\Async\Http\Client\Connection\Stream;
use Fledge\Async\Http\Client\DelegateHttpClient;
use Fledge\Async\Http\Client\NetworkInterceptor;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;

class ModifyRequest implements NetworkInterceptor, ApplicationInterceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param \Closure(Request):(Request|null) $mapper
     */
    public function __construct(private readonly \Closure $mapper)
    {
    }

    final public function requestViaNetwork(
        Request $request,
        Cancellation $cancellation,
        Stream $stream
    ): Response {
        $mappedRequest = ($this->mapper)($request);

        \assert($mappedRequest instanceof Request || $mappedRequest === null);

        return $stream->request($mappedRequest ?? $request, $cancellation);
    }

    public function request(
        Request $request,
        Cancellation $cancellation,
        DelegateHttpClient $httpClient
    ): Response {
        $mappedRequest = ($this->mapper)($request);

        \assert($mappedRequest instanceof Request || $mappedRequest === null);

        return $httpClient->request($mappedRequest ?? $request, $cancellation);
    }
}
