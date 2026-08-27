<?php

use Fledge\Async\Stream\ResourceServerSocket;
use Fledge\Async\Stream\Socket;
use Fledge\Async\Http\Server\Response as ServerResponse;

use function Fledge\Async\async;
use function Fledge\Async\Stream\connect;
use function Fledge\Async\Stream\listen;

require_once __DIR__.'/../Fixtures/loopback.php';

function proxy_read_exact(Socket $socket, int $length): string
{
    $buffer = '';

    while (strlen($buffer) < $length) {
        $chunk = $socket->read(null, $length - strlen($buffer));

        if ($chunk === null) {
            throw new RuntimeException('Socket closed during proxy handshake');
        }

        $buffer .= $chunk;
    }

    return $buffer;
}

/**
 * Pump bytes both ways between the proxy client and the upstream origin.
 */
function proxy_relay(Socket $client, Socket $upstream): void
{
    (void) async(function () use ($client, $upstream) {
        try {
            while (($chunk = $client->read()) !== null) {
                $upstream->write($chunk);
            }
        } catch (\Throwable) {
        } finally {
            $upstream->close();
        }
    });

    try {
        while (($chunk = $upstream->read()) !== null) {
            $client->write($chunk);
        }
    } catch (\Throwable) {
    } finally {
        $client->close();
    }
}

/**
 * Minimal in-process SOCKS5 proxy recording each CONNECT target authority.
 *
 * @return array{ResourceServerSocket, int}
 */
function startSocks5Stub(ArrayObject $targets): array
{
    $server = listen('127.0.0.1:0');
    $port = $server->getAddress()->getPort();

    (void) async(function () use ($server, $targets) {
        while (($client = $server->accept()) !== null) {
            (void) async(function () use ($client, $targets) {
                try {
                    $greeting = proxy_read_exact($client, 2);
                    proxy_read_exact($client, ord($greeting[1]));
                    $client->write("\x05\x00");

                    $head = proxy_read_exact($client, 4);
                    $host = match (ord($head[3])) {
                        0x01 => inet_ntop(proxy_read_exact($client, 4)),
                        0x03 => proxy_read_exact($client, ord(proxy_read_exact($client, 1))),
                        0x04 => inet_ntop(proxy_read_exact($client, 16)),
                    };
                    $targetPort = unpack('n', proxy_read_exact($client, 2))[1];

                    $targets[] = "{$host}:{$targetPort}";

                    $client->write("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

                    proxy_relay($client, connect("tcp://{$host}:{$targetPort}"));
                } catch (\Throwable) {
                    $client->close();
                }
            });
        }
    });

    return [$server, $port];
}

/**
 * Minimal in-process HTTP CONNECT proxy recording target authorities and
 * any Proxy-Authorization header it receives.
 *
 * @return array{ResourceServerSocket, int}
 */
function startConnectStub(ArrayObject $records): array
{
    $server = listen('127.0.0.1:0');
    $port = $server->getAddress()->getPort();

    (void) async(function () use ($server, $records) {
        while (($client = $server->accept()) !== null) {
            (void) async(function () use ($client, $records) {
                try {
                    $head = '';

                    while (! str_contains($head, "\r\n\r\n")) {
                        $chunk = $client->read();

                        if ($chunk === null) {
                            throw new RuntimeException('Socket closed during CONNECT');
                        }

                        $head .= $chunk;
                    }

                    if (! preg_match('(^CONNECT ([^ ]+) HTTP/1\.1\r\n)', $head, $match)) {
                        $client->write("HTTP/1.1 400 Bad Request\r\n\r\n");
                        $client->close();

                        return;
                    }

                    preg_match('(\r\nProxy-Authorization: ([^\r\n]+))i', $head, $auth);

                    $records[] = [
                        'authority' => $match[1],
                        'authorization' => $auth[1] ?? null,
                    ];

                    $upstream = connect('tcp://'.$match[1]);
                    $client->write("HTTP/1.1 200 Connection established\r\n\r\n");

                    proxy_relay($client, $upstream);
                } catch (\Throwable) {
                    $client->close();
                }
            });
        }
    });

    return [$server, $port];
}

it('routes requests through a socks5 proxy', function () {
    [$origin, $originPort] = startLoopbackServer(null, fn (): ServerResponse => new ServerResponse(200, [], 'origin ok'));

    $targets = new ArrayObject;
    [$proxy, $proxyPort] = startSocks5Stub($targets);

    // Socks5SocketConnector::tunnel still calls the deprecated
    // League\Uri\Uri::createFromString; keep that upstream deprecation out
    // of this suite's report.
    set_error_handler(fn (): bool => true, E_DEPRECATED | E_USER_DEPRECATED);

    try {
        $response = makeGuzzleClient(['proxy' => "socks5://127.0.0.1:{$proxyPort}"])
            ->get("http://127.0.0.1:{$originPort}/");

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('origin ok')
            ->and($targets->getArrayCopy())->toBe(["127.0.0.1:{$originPort}"]);
    } finally {
        restore_error_handler();
        $proxy->close();
        $origin->stop();
    }
});

it('routes requests through an http connect proxy with credentials', function () {
    [$origin, $originPort] = startLoopbackServer(null, fn (): ServerResponse => new ServerResponse(200, [], 'origin ok'));

    $records = new ArrayObject;
    [$proxy, $proxyPort] = startConnectStub($records);

    try {
        $response = makeGuzzleClient(['proxy' => "http://user:secret@127.0.0.1:{$proxyPort}"])
            ->get("http://127.0.0.1:{$originPort}/");

        $recorded = $records->getArrayCopy();

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('origin ok')
            ->and($recorded)->toHaveCount(1)
            ->and($recorded[0]['authority'])->toBe("127.0.0.1:{$originPort}")
            ->and($recorded[0]['authorization'])->toBe('Basic '.base64_encode('user:secret'));
    } finally {
        $proxy->close();
        $origin->stop();
    }
});

it('bypasses the proxy for hosts in the no list', function () {
    [$origin, $originPort] = startLoopbackServer(null, fn (): ServerResponse => new ServerResponse(200, [], 'direct ok'));

    // The proxy target is a dead port: only a direct connection can succeed.
    $deadProxyPort = refusedPort();

    try {
        $response = makeGuzzleClient([
            'proxy' => [
                'http' => "http://127.0.0.1:{$deadProxyPort}",
                'no' => ['127.0.0.1'],
            ],
        ])->get("http://127.0.0.1:{$originPort}/");

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('direct ok');
    } finally {
        $origin->stop();
    }
});
