<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final class InterceptedHttpClient implements DelegateHttpClient
{
    use ForbidCloning;
    use ForbidSerialization;

    private static \WeakMap $requestInterceptors;

    /**
     * @param EventListener[] $eventListeners
     */
    public function __construct(
        private readonly DelegateHttpClient $httpClient,
        private readonly ApplicationInterceptor $interceptor,
        private readonly array $eventListeners,
    ) {
    }

    public function request(Request $request, Cancellation $cancellation): Response
    {
        return processRequest($request, $this->eventListeners, function () use ($request, $cancellation) {
            /** @psalm-suppress RedundantPropertyInitializationCheck */
            self::$requestInterceptors ??= new \WeakMap();

            $requestInterceptors = self::$requestInterceptors[$request] ?? [];
            $requestInterceptors[] = $this->interceptor;
            self::$requestInterceptors[$request] = $requestInterceptors;

            events()->applicationInterceptorStart($request, $this->interceptor);

            $response = $this->interceptor->request($request, $cancellation, $this->httpClient);

            events()->applicationInterceptorEnd($request, $this->interceptor, $response);

            return $response;
        });
    }
}
