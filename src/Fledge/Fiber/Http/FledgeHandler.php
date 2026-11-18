<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\CancelledException;
use Fledge\Async\Http\Client\BufferedContent;
use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\Interceptor\FollowRedirects;
use Fledge\Async\Http\Client\Request as AsyncRequest;
use Fledge\Async\Http\Client\Response as AsyncResponse;
use Fledge\Async\Http\Client\StreamedContent;
use Fledge\Async\Http\Client\TimeoutException as ClientTimeoutException;
use Fledge\Async\Stream\Payload;
use Fledge\Async\TimeoutCancellation;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriComparator;
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
    /**
     * Seconds used for TCP connect and TLS handshake when connect_timeout is
     * absent or 0. This is curl's built-in connect timeout, which applies
     * when Guzzle sets none. A positive timeout still caps it, as curl does.
     */
    public const NO_CONNECT_TIMEOUT = 300.0;

    /**
     * Request bodies of at least this many bytes are streamed, matching the
     * size below which Guzzle's CurlFactory sends the body as one string.
     */
    public const STREAM_BODY_THRESHOLD = 1000000;

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
            $request = $this->restrictRedirectReferer($request, $options);

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

            // Guzzle's timeout is curl's CURLOPT_TIMEOUT, whose clock also runs
            // while the request waits for a free slot in a connection-limited
            // pool. The transfer timeout only starts once a stream exists, so
            // bound the whole request with the same limit.
            $timeout = $asyncRequest->getTransferTimeout();
            $cancellation = $timeout > 0 ? new TimeoutCancellation($timeout) : null;

            try {
                return $client->request($asyncRequest, $cancellation);
            } catch (CancelledException $e) {
                if ($cancellation?->isRequested()) {
                    throw new ClientTimeoutException('Allowed transfer timeout exceeded, took longer than '.$timeout.' s', 0, $e);
                }

                throw $e;
            }
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
     * Reduce the Referer on a redirect hop to what a browser would send under
     * strict-origin-when-cross-origin: never userinfo or fragment, and only
     * the origin when the hop leaves the referring origin.
     *
     * Guzzle 8's RedirectMiddleware already does this, but Guzzle 7 sends the
     * full previous URL, path and query included, to the new origin. Doing it
     * here keeps the guarantee whichever Guzzle version is installed. A
     * Referer the caller sets on the first request is left alone: only hops
     * the RedirectMiddleware issued carry __redirect_count.
     */
    protected function restrictRedirectReferer(RequestInterface $request, array $options): RequestInterface
    {
        if (empty($options['__redirect_count']) || ! $request->hasHeader('Referer')) {
            return $request;
        }

        try {
            $referer = new Uri($request->getHeaderLine('Referer'));
        } catch (\InvalidArgumentException) {
            return $request->withoutHeader('Referer');
        }

        $restricted = $referer->withUserInfo('')->withFragment('');

        if (UriComparator::isCrossOrigin($referer, $request->getUri())) {
            $restricted = $restricted->withPath('/')->withQuery('');
        }

        return $request->withHeader('Referer', (string) $restricted);
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

        // Redirects belong to Guzzle's RedirectMiddleware, which strips credentials
        // on cross-origin hops. A fixed client built with transport redirects must not
        // follow a second time behind its back.
        $asyncRequest->setAttribute(FollowRedirects::ATTRIBUTE, false);

        // Copy headers
        foreach ($request->getHeaders() as $name => $values) {
            $asyncRequest->setHeader($name, $values);
        }

        $this->copyBody($request, $asyncRequest);

        // Map timeouts with Guzzle semantics: an absent or zero value waits
        // indefinitely instead of inheriting the async client's 10 s defaults.
        // The client treats a transfer or inactivity timeout of 0 as disabled.
        $timeout = max(0.0, (float) ($options['timeout'] ?? 0));
        $asyncRequest->setTransferTimeout($timeout);

        // read_timeout bounds the wait between reads, which is the client's
        // inactivity timeout; without it the overall timeout covers that too.
        $readTimeout = isset($options['read_timeout']) ? max(0.0, (float) $options['read_timeout']) : $timeout;
        $asyncRequest->setInactivityTimeout($readTimeout);

        // The transfer timeout only starts once a connection exists, while
        // Guzzle and curl count connect time inside timeout. An absent or 0
        // connect_timeout means curl's built-in 300 s connect timeout (TCP
        // connect rejects 0, and a 0 handshake would time out at once). curl
        // caps the connect phase at a positive total timeout, so the smaller
        // of the two wins.
        $connectTimeout = (float) ($options['connect_timeout'] ?? 0);
        $connectTimeout = $connectTimeout > 0 ? $connectTimeout : self::NO_CONNECT_TIMEOUT;

        if ($timeout > 0) {
            $connectTimeout = min($connectTimeout, $timeout);
        }
        $asyncRequest->setTcpConnectTimeout($connectTimeout);
        $asyncRequest->setTlsHandshakeTimeout($connectTimeout);

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

        // Guzzle has no body cap, so neither do we unless max_body_size asks
        // for one. PHP_INT_MAX rather than 0: the HTTP/2 flow-control window
        // is sized from min(limit, 512 KiB) and stalls on a limit of 0.
        $maxBodySize = (int) ($options['max_body_size'] ?? 0);
        $asyncRequest->setBodySizeLimit($maxBodySize > 0 ? $maxBodySize : PHP_INT_MAX);

        // A string decode_content value advertises that encoding without any
        // automatic decompression: the DecompressResponse interceptor leaves
        // requests with a manually set Accept-Encoding header untouched.
        if (\is_string($options['decode_content'] ?? null)) {
            $asyncRequest->setHeader('Accept-Encoding', $options['decode_content']);
        }

        return $asyncRequest;
    }

    /**
     * Copy the PSR-7 body onto the async request the way Guzzle's curl
     * handler sends it: a body known to be under 1 MB is sent as one string,
     * a larger one or a chunked body of unknown size is streamed in 64 KiB
     * reads (CURLOPT_READFUNCTION) instead of being copied whole into memory.
     * The size comes from the Content-Length header, as in curl, falling back
     * to the stream size. A body of unknown size without a Transfer-Encoding
     * header stays buffered so it keeps its Content-Length framing.
     */
    protected function copyBody(RequestInterface $request, AsyncRequest $asyncRequest): void
    {
        $body = $request->getBody();
        $contentType = $request->getHeaderLine('Content-Type') ?: null;
        $contentLength = $request->getHeaderLine('Content-Length');
        $size = is_numeric($contentLength) ? (int) $contentLength : $body->getSize();

        $stream = $size === null
            ? $request->hasHeader('Transfer-Encoding')
            : $size >= self::STREAM_BODY_THRESHOLD;

        if ($stream) {
            $asyncRequest->setBody(StreamedContent::fromStream(new Psr7ReadableStream($body), $size, $contentType));

            return;
        }

        $body = (string) $body;

        if ($body !== '') {
            $asyncRequest->setBody(BufferedContent::fromString($body, $contentType));
        }
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
