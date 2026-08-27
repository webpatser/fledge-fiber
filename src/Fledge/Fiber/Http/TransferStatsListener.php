<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Http\Client\ApplicationInterceptor;
use Fledge\Async\Http\Client\Connection\Connection;
use Fledge\Async\Http\Client\Connection\Stream;
use Fledge\Async\Http\Client\EventListener;
use Fledge\Async\Http\Client\NetworkInterceptor;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\InternetAddress;

/**
 * Collects curl-shaped transfer statistics from the async client's request
 * events, attached per request when the on_stats option is present.
 *
 * Durations are relative to the moment the handler dispatched the request,
 * mirroring what curl reports relative to the transfer start.
 */
class TransferStatsListener implements EventListener
{
    public ?float $connectTime = null;

    public ?float $appconnectTime = null;

    public ?string $primaryIp = null;

    public ?int $primaryPort = null;

    public ?string $alpn = null;

    public ?float $pretransferTime = null;

    public ?float $starttransferTime = null;

    public ?int $sizeDownload = null;

    public function __construct(protected float $startTime) {}

    public function connectionAcquired(Request $request, Connection $connection, int $streamCount): void
    {
        $this->connectTime = $connection->getConnectDuration();
        $this->appconnectTime = $connection->getTlsHandshakeDuration();

        $remote = $connection->getRemoteAddress();

        if ($remote instanceof InternetAddress) {
            $this->primaryIp = $remote->getAddress();
            $this->primaryPort = $remote->getPort();
        }

        $this->alpn = $connection->getTlsInfo()?->getApplicationLayerProtocol();
    }

    public function requestHeaderStart(Request $request, Stream $stream): void
    {
        $this->pretransferTime ??= microtime(true) - $this->startTime;
    }

    public function responseHeaderStart(Request $request, Stream $stream): void
    {
        $this->starttransferTime ??= microtime(true) - $this->startTime;
    }

    public function responseHeaderEnd(Request $request, Stream $stream, Response $response): void
    {
        // Initial estimate from the headers; the handler overrides this with
        // the actual byte count wherever it drains the body itself.
        $length = $response->getHeader('content-length');

        if (is_numeric($length)) {
            $this->sizeDownload = (int) $length;
        }
    }

    public function requestStart(Request $request): void {}

    public function requestFailed(Request $request, \Throwable $exception): void {}

    public function requestEnd(Request $request, Response $response): void {}

    public function requestRejected(Request $request): void {}

    public function applicationInterceptorStart(Request $request, ApplicationInterceptor $interceptor): void {}

    public function applicationInterceptorEnd(Request $request, ApplicationInterceptor $interceptor, Response $response): void {}

    public function networkInterceptorStart(Request $request, NetworkInterceptor $interceptor): void {}

    public function networkInterceptorEnd(Request $request, NetworkInterceptor $interceptor, Response $response): void {}

    public function push(Request $request): void {}

    public function requestHeaderEnd(Request $request, Stream $stream): void {}

    public function requestBodyStart(Request $request, Stream $stream): void {}

    public function requestBodyProgress(Request $request, Stream $stream): void {}

    public function requestBodyEnd(Request $request, Stream $stream): void {}

    public function responseBodyStart(Request $request, Stream $stream, Response $response): void {}

    public function responseBodyProgress(Request $request, Stream $stream, Response $response): void {}

    public function responseBodyEnd(Request $request, Stream $stream, Response $response): void {}
}
