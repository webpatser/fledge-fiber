<?php

/**
 * Raw HTTP/1 loopback helpers: a plain-TCP server around any request handler
 * and a socket client that puts arbitrary bytes on the wire, for requests a
 * real client would never send. Every read is bounded with LOOPBACK_TIMEOUT.
 */

use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\TimeoutCancellation;
use Psr\Log\NullLogger;

use function Fledge\Async\Stream\connect;

require_once __DIR__.'/loopback.php';

if (! function_exists('startHttp1RawServer')) {
    /** @return array{SocketHttpServer, int} [server, port] */
    function startHttp1RawServer(?RequestHandler $handler = null, ?SocketHttpServer $server = null): array
    {
        $server ??= SocketHttpServer::createForDirectAccess(new NullLogger);
        $server->expose('127.0.0.1:0');
        $server->start($handler, new DefaultErrorHandler);

        return [$server, $server->getServers()[0]->getAddress()->getPort()];
    }

    /** Send raw bytes and return everything the server answers until it closes or the bound fires. */
    function rawHttp1Exchange(int $port, string $payload): string
    {
        $cancellation = new TimeoutCancellation(LOOPBACK_TIMEOUT);
        $socket = connect("tcp://127.0.0.1:{$port}", null, $cancellation);
        $socket->write($payload);

        $response = '';
        try {
            while (($chunk = $socket->read($cancellation)) !== null) {
                $response .= $chunk;
            }
        } catch (Throwable) {
            // timed out or reset: return what we have
        }
        $socket->close();

        return $response;
    }

    /** Status code of a raw response (the server answers HTTP/1.0 on some error paths). */
    function rawHttp1Status(string $response): int
    {
        return preg_match('#^HTTP/1\.[01] (\d{3})#', $response, $m) ? (int) $m[1] : 0;
    }
}
