<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\HttpClientBuilder;

/**
 * Builds the async HTTP clients used by FledgeHandler.
 *
 * The default client disables the transport's own redirect and retry
 * interceptors (followRedirects(0) and retry(0) null both out in
 * HttpClientBuilder), so the layers above own those behaviors again:
 * Guzzle's RedirectMiddleware handles allow_redirects with all its options
 * (max, protocols, strict 307/308 replays, referer handling, per-hop
 * cookies, effective URI tracking), and Laravel's Http::retry is the only
 * retry mechanism instead of stacking on a hidden RetryRequests(2).
 */
class AsyncClientFactory
{
    protected ?HttpClient $default = null;

    /**
     * The default client: no transport redirects, no transport retries.
     */
    public function default(): HttpClient
    {
        return $this->default ??= (new HttpClientBuilder)
            ->followRedirects(0)
            ->retry(0)
            ->build();
    }
}
