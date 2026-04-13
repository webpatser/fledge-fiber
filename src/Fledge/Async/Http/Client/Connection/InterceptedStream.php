<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\NetworkInterceptor;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use function Fledge\Async\Http\Client\events;
use function Fledge\Async\Http\Client\processRequest;

final class InterceptedStream implements Stream
{
    use ForbidCloning;
    use ForbidSerialization;

    private static \WeakMap $requestInterceptors;

    private ?NetworkInterceptor $interceptor;

    public function __construct(private readonly Stream $stream, NetworkInterceptor $interceptor)
    {
        $this->interceptor = $interceptor;
    }

    /**
     * @throws HttpException
     */
    public function request(Request $request, Cancellation $cancellation): Response
    {
        return processRequest($request, [], function () use ($request, $cancellation): Response {
            $interceptor = $this->interceptor;
            $this->interceptor = null;

            if (!$interceptor) {
                throw new \Error(__METHOD__ . ' may only be invoked once per instance. '
                    . 'If you need to implement retries or otherwise issue multiple requests, register an ApplicationInterceptor to do so.');
            }

            /** @psalm-suppress RedundantPropertyInitializationCheck */
            self::$requestInterceptors ??= new \WeakMap();
            $requestInterceptors = self::$requestInterceptors[$request] ?? [];
            $requestInterceptors[] = $interceptor;
            self::$requestInterceptors[$request] = $requestInterceptors;

            events()->networkInterceptorStart($request, $interceptor);

            $response = $interceptor->requestViaNetwork($request, $cancellation, $this->stream);

            events()->networkInterceptorEnd($request, $interceptor, $response);

            return $response;
        });
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->stream->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->stream->getRemoteAddress();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->stream->getTlsInfo();
    }
}
