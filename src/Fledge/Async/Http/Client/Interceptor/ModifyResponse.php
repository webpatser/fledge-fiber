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

class ModifyResponse implements NetworkInterceptor, ApplicationInterceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param \Closure(Response):Response $mapper
     */
    public function __construct(private readonly \Closure $mapper)
    {
    }

    final public function requestViaNetwork(
        Request $request,
        Cancellation $cancellation,
        Stream $stream
    ): Response {
        $response = $stream->request($request, $cancellation);
        $mappedResponse = ($this->mapper)($response);

        \assert($mappedResponse instanceof Response || $mappedResponse === null);

        return $mappedResponse ?? $response;
    }

    public function request(
        Request $request,
        Cancellation $cancellation,
        DelegateHttpClient $httpClient
    ): Response {
        $request->interceptPush(fn (Request $request, Response $response) => ($this->mapper)($response));

        $response = $httpClient->request($request, $cancellation);
        $mappedResponse = ($this->mapper)($response);

        \assert($mappedResponse instanceof Response || $mappedResponse === null);

        return $mappedResponse ?? $response;
    }
}
