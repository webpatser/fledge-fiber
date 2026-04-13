<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\Http\Client\DelegateHttpClient;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;

interface Stream extends DelegateHttpClient
{
    /**
     * Executes the request.
     *
     * This method may only be invoked once per instance.
     *
     * The implementation must ensure that events are called on {@see events()} and may use {@see request()} for that.
     *
     * @throws \Error Thrown if this method is called more than once.
     */
    public function request(Request $request, Cancellation $cancellation): Response;

    public function getLocalAddress(): SocketAddress;

    public function getRemoteAddress(): SocketAddress;

    public function getTlsInfo(): ?TlsInfo;
}
