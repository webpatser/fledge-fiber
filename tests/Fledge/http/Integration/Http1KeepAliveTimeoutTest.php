<?php

use Fledge\Async\Http\Server\Driver\DefaultHttpDriverFactory;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\TimeoutCancellation;
use Psr\Log\NullLogger;

use function Fledge\Async\Stream\connect;

require_once __DIR__.'/../Fixtures/raw_http1.php';

/**
 * Start a server whose HTTP/1 idle timeout is one second, send one keep-alive request, read the
 * response and report whether the server closed the idle connection within a generous bound.
 */
function http1IdleConnectionCloses(string $method): bool
{
    $server = SocketHttpServer::createForDirectAccess(
        new NullLogger,
        httpDriverFactory: new DefaultHttpDriverFactory(new NullLogger, streamTimeout: 1),
    );

    [$server, $port] = startHttp1RawServer(
        new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, ['content-type' => 'text/plain'], 'hello')),
        $server,
    );

    try {
        $socket = connect("tcp://127.0.0.1:{$port}", null, new TimeoutCancellation(LOOPBACK_TIMEOUT));
        $socket->write("{$method} / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        // The connection stays open (keep-alive), so read until the bound fires or the server closes it.
        // The idle timeout is one second; five seconds leaves room for the once-per-second timeout tick.
        $cancellation = new TimeoutCancellation(5);
        $response = '';
        $closed = false;

        try {
            while (($chunk = $socket->read($cancellation)) !== null) {
                $response .= $chunk;
            }
            $closed = true;
        } catch (Throwable) {
            // Bound fired: the server kept the idle connection open.
        }

        $socket->close();

        expect(rawHttp1Status($response))->toBe(200);

        return $closed;
    } finally {
        $server->stop();
    }
}

it('closes an idle keep-alive connection after a HEAD response', function () {
    expect(http1IdleConnectionCloses('HEAD'))->toBeTrue();
});

it('closes an idle keep-alive connection after a GET response', function () {
    expect(http1IdleConnectionCloses('GET'))->toBeTrue();
});

it('keeps serving requests on a keep-alive connection after a HEAD response', function () {
    [$server, $port] = startHttp1RawServer(
        new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, ['content-type' => 'text/plain'], 'hello')),
    );

    try {
        $response = rawHttp1Exchange(
            $port,
            "HEAD / HTTP/1.1\r\nHost: localhost\r\n\r\nGET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
        );

        expect(preg_match_all('#^HTTP/1\.1 200#m', $response))->toBe(2)
            ->and($response)->toEndWith('hello');
    } finally {
        $server->stop();
    }
});
