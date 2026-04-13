<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Http\Client\BufferedContent;
use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request as AsyncRequest;
use Fledge\Async\Http\Client\Response as AsyncResponse;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;

use function Fledge\Async\async;
use function GuzzleHttp\Psr7\stream_for;

/**
 * Guzzle handler backed by Fledge Async HTTP client for non-blocking I/O.
 *
 * Replaces CurlHandler as the default transport. All Guzzle middleware
 * (including stubbing, recording, and user middleware) runs unchanged
 * on top of this handler.
 *
 * Each request is dispatched via Fledge\Async\async(), which starts it on the
 * Revolt event loop immediately. The returned Guzzle Promise resolves
 * when Future::await() completes. Multiple concurrent requests (e.g.,
 * from Http::pool()) all progress when any single await() drives the
 * event loop.
 */
class FledgeHandler
{
    protected HttpClient $client;

    public function __construct(?HttpClient $client = null)
    {
        $this->client = $client ?? HttpClientBuilder::buildDefault();
    }

    /**
     * Send an HTTP request via Fledge Async HTTP client.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $asyncRequest = $this->createAsyncRequest($request, $options);

        $future = async(fn () => $this->client->request($asyncRequest));

        $promise = new Promise(function () use (&$promise, $future, $request, $options) {
            $startTime = microtime(true);

            try {
                $asyncResponse = $future->await();
                $response = $this->createPsr7Response($asyncResponse);

                $this->invokeStats($request, $options, $response, $startTime);

                $promise->resolve($response);
            } catch (\Throwable $e) {
                $this->invokeStats($request, $options, null, $startTime, $e);

                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Convert a PSR-7 request to a Fledge Async request with Guzzle options applied.
     */
    protected function createAsyncRequest(RequestInterface $request, array $options): AsyncRequest
    {
        $asyncRequest = new AsyncRequest(
            (string) $request->getUri(),
            $request->getMethod()
        );

        // Copy headers
        foreach ($request->getHeaders() as $name => $values) {
            $asyncRequest->setHeader($name, $values);
        }

        // Copy body
        $body = (string) $request->getBody();

        if ($body !== '') {
            $contentType = $request->getHeaderLine('Content-Type') ?: null;
            $asyncRequest->setBody(BufferedContent::fromString($body, $contentType));
        }

        // Map timeouts
        if (isset($options['timeout']) && $options['timeout'] > 0) {
            $asyncRequest->setTransferTimeout((float) $options['timeout']);
            $asyncRequest->setInactivityTimeout((float) $options['timeout']);
        }

        if (isset($options['connect_timeout']) && $options['connect_timeout'] > 0) {
            $asyncRequest->setTcpConnectTimeout((float) $options['connect_timeout']);
            $asyncRequest->setTlsHandshakeTimeout((float) $options['connect_timeout']);
        }

        // Protocol version
        $version = $request->getProtocolVersion();

        if ($version) {
            $asyncRequest->setProtocolVersions([$version]);
        }

        // Body size limit (for large responses)
        if (isset($options['max_body_size'])) {
            $asyncRequest->setBodySizeLimit((int) $options['max_body_size']);
        }

        return $asyncRequest;
    }

    /**
     * Convert a Fledge Async response to a PSR-7 response.
     */
    protected function createPsr7Response(AsyncResponse $asyncResponse): Psr7Response
    {
        $body = $asyncResponse->getBody()->buffer();

        return new Psr7Response(
            $asyncResponse->getStatus(),
            $asyncResponse->getHeaders(),
            $body,
            $asyncResponse->getProtocolVersion(),
            $asyncResponse->getReason(),
        );
    }

    /**
     * Invoke the on_stats callback if present.
     */
    protected function invokeStats(
        RequestInterface $request,
        array $options,
        ?Psr7Response $response,
        float $startTime,
        ?\Throwable $error = null,
    ): void {
        if (isset($options['on_stats'])) {
            $transferTime = microtime(true) - $startTime;

            $stats = new TransferStats(
                $request,
                $response,
                $transferTime,
                $error,
                [
                    'total_time' => $transferTime,
                    'http_code' => $response?->getStatusCode() ?? 0,
                    'handler' => 'fledge',
                ],
            );

            ($options['on_stats'])($stats);
        }
    }
}
