<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client;

use Fledge\Async\Cancellation;
use Fledge\Async\NullCancellation;

/**
 * Convenient HTTP client for use in applications and libraries, providing a default for the cancellation and
 * automatically cloning the passed request, so future application requests can re-use the same object again.
 */
final readonly class HttpClient implements DelegateHttpClient
{
    /**
     * @param EventListener[] $eventListeners
     */
    public function __construct(
        private DelegateHttpClient $httpClient,
        private array $eventListeners,
    ) {
    }

    /**
     * Request a specific resource from an HTTP server.
     *
     * @throws HttpException
     */
    public function request(Request $request, ?Cancellation $cancellation = null): Response
    {
        return processRequest(
            $request,
            $this->eventListeners,
            fn () => $this->httpClient->request($request, $cancellation ?? new NullCancellation()),
        );
    }
}
