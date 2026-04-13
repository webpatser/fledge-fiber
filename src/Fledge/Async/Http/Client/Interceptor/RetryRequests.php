<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\ApplicationInterceptor;
use Fledge\Async\Http\Client\DelegateHttpClient;
use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;

final class RetryRequests implements ApplicationInterceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(private readonly int $retryLimit)
    {
    }

    public function request(
        Request $request,
        Cancellation $cancellation,
        DelegateHttpClient $httpClient
    ): Response {
        $attempt = 1;

        do {
            $clonedRequest = clone $request;

            try {
                return $httpClient->request($request, $cancellation);
            } catch (HttpException $exception) {
                if ($request->isIdempotent() || $request->isUnprocessed()) {
                    // Request was deemed retryable by connection, so carry on.
                    $request = $clonedRequest;
                    continue;
                }

                throw $exception;
            }
        } while ($attempt++ <= $this->retryLimit);

        throw $exception;
    }
}
