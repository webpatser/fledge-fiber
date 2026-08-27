<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Http\Client\BufferedContent;
use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\Request as AsyncRequest;
use Fledge\Async\Http\Client\Response as AsyncResponse;
use Fledge\Async\Stream\Payload;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

use function Fledge\Async\async;
use function Fledge\Async\delay;

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
    protected ?HttpClient $client;

    protected AsyncClientFactory $factory;

    /**
     * @param HttpClient|AsyncClientFactory|null $client A fixed client to send every request
     *                                                   through, a factory to build clients from
     *                                                   request options, or null for the default
     *                                                   factory.
     */
    public function __construct(HttpClient|AsyncClientFactory|null $client = null)
    {
        $this->client = $client instanceof HttpClient ? $client : null;
        $this->factory = $client instanceof AsyncClientFactory ? $client : new AsyncClientFactory;
    }

    /**
     * Send an HTTP request via Fledge Async HTTP client.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $asyncRequest = $this->createAsyncRequest($request, $options);

            $client = $this->client ?? $this->factory->clientFor($options, $request->getUri());
        } catch (\Throwable $e) {
            return Create::rejectionFor(GuzzleExceptionMapper::map($e, $request));
        }

        // Capture the start before dispatching: the request begins on the
        // event loop immediately, so starting the clock inside the wait
        // callback would report near-zero timings under Http::pool().
        $startTime = microtime(true);
        $listener = null;

        if (isset($options['on_stats'])) {
            $listener = new TransferStatsListener($startTime);
            $asyncRequest->addEventListener($listener);
        }

        $delay = ($options['delay'] ?? 0) / 1000;

        $future = async(function () use ($client, $asyncRequest, $delay) {
            if ($delay > 0) {
                // Non-blocking: only this request's fiber sleeps, so other
                // requests on the loop keep progressing.
                delay($delay);
            }

            return $client->request($asyncRequest);
        });

        $promise = new Promise(function () use (&$promise, $future, $request, $options, $startTime, $listener) {
            try {
                $asyncResponse = $future->await();
                $response = $this->createPsr7Response($asyncResponse, $request, $options, $listener);

                $this->invokeStats($request, $options, $response, $startTime, null, $listener);

                $promise->resolve($response);
            } catch (\Throwable $e) {
                $e = GuzzleExceptionMapper::map($e, $request);

                $this->invokeStats($request, $options, null, $startTime, $e, $listener);

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

        // Protocol version. Guzzle stamps every PSR-7 request "1.1" unless the
        // caller passes the 'version' request option, where HTTP/2 arrives as
        // "2" or "2.0" depending on how the option was written. An explicit
        // HTTP/2 choice offers h2 with an HTTP/1.1 fallback, matching curl:
        // ALPN negotiates h2 where the server supports it, and plain-http
        // targets fall back to an Http1Connection instead of forcing prior
        // knowledge h2c. Everything else stays on its literal version, keeping
        // default traffic on HTTP/1.1.
        $asyncRequest->setProtocolVersions(match ((string) $request->getProtocolVersion()) {
            '2', '2.0' => ['2', '1.1'],
            '1.0' => ['1.0'],
            default => ['1.1'],
        });

        // Body size limit (for large responses)
        if (isset($options['max_body_size'])) {
            $asyncRequest->setBodySizeLimit((int) $options['max_body_size']);
        }

        // A string decode_content value advertises that encoding without any
        // automatic decompression: the DecompressResponse interceptor leaves
        // requests with a manually set Accept-Encoding header untouched.
        if (\is_string($options['decode_content'] ?? null)) {
            $asyncRequest->setHeader('Accept-Encoding', $options['decode_content']);
        }

        return $asyncRequest;
    }

    /**
     * Convert a Fledge Async response to a PSR-7 response.
     *
     * The response is built from the headers first so on_headers callbacks
     * run before the body is drained, matching Guzzle's curl handler. The
     * body then honors the stream and sink options: stream=true wraps the
     * payload in a lazy AsyncBodyStream, a sink drains into the caller's
     * target, and the default buffers in memory.
     */
    protected function createPsr7Response(
        AsyncResponse $asyncResponse,
        RequestInterface $request,
        array $options,
        ?TransferStatsListener $listener = null,
    ): Psr7Response {
        $response = new Psr7Response(
            $asyncResponse->getStatus(),
            $asyncResponse->getHeaders(),
            null,
            $asyncResponse->getProtocolVersion(),
            $asyncResponse->getReason(),
        );

        if (isset($options['on_headers'])) {
            try {
                ($options['on_headers'])($response);
            } catch (\Throwable $e) {
                throw GuzzleExceptionMapper::onHeadersException($e, $request, $response);
            }
        }

        $payload = $asyncResponse->getBody();
        $sink = $options['sink'] ?? null;

        if ($sink !== null) {
            return $response->withBody($this->drainToSink($payload, $sink, $listener));
        }

        if ($options['stream'] ?? false) {
            $contentLength = $asyncResponse->getHeader('content-length');

            return $response->withBody(new AsyncBodyStream(
                $payload,
                is_numeric($contentLength) ? (int) $contentLength : null,
            ));
        }

        $body = $payload->buffer();

        if ($listener !== null) {
            $listener->sizeDownload = strlen($body);
        }

        return $response->withBody(Utils::streamFor($body));
    }

    /**
     * Stream the response payload into the caller's sink and return the
     * stream backing the response body.
     */
    protected function drainToSink(Payload $payload, mixed $sink, ?TransferStatsListener $listener = null): StreamInterface
    {
        $bytes = 0;

        if (\is_string($sink)) {
            $target = Utils::streamFor(Utils::tryFopen($sink, 'w+b'));

            while (($chunk = $payload->read()) !== null) {
                $bytes += $target->write($chunk);
            }

            $target->close();

            if ($listener !== null) {
                $listener->sizeDownload = $bytes;
            }

            // Back the response with a fresh handle like Guzzle's CurlFactory.
            return new LazyOpenStream($sink, 'r+');
        }

        $target = $sink instanceof StreamInterface ? $sink : Utils::streamFor($sink);

        while (($chunk = $payload->read()) !== null) {
            $bytes += $target->write($chunk);
        }

        if ($listener !== null) {
            $listener->sizeDownload = $bytes;
        }

        if ($target->isSeekable()) {
            $target->rewind();
        }

        return $target;
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
        ?TransferStatsListener $listener = null,
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
                    'namelookup_time' => 0.0,
                    'connect_time' => $listener?->connectTime ?? 0.0,
                    'appconnect_time' => $listener?->appconnectTime ?? 0.0,
                    'pretransfer_time' => $listener?->pretransferTime ?? 0.0,
                    'starttransfer_time' => $listener?->starttransferTime ?? 0.0,
                    'primary_ip' => $listener?->primaryIp ?? '',
                    'primary_port' => $listener?->primaryPort ?? 0,
                    'size_download' => $listener?->sizeDownload ?? 0,
                    'http_code' => $response?->getStatusCode() ?? 0,
                    'url' => (string) $request->getUri(),
                    'handler' => 'fledge',
                ],
            );

            ($options['on_stats'])($stats);
        }
    }
}
