<?php

declare(strict_types=1);

use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\DnsSocketConnector;
use Fledge\Async\Stream\ResourceStream;

/*
 * Keep-alive and buffer size options travel as PHP 8.6 stream socket context options. The live tests read the
 * value back from the OS with ext-sockets (test code only; the library no longer needs it).
 */

function socketOptionKeepIdle(): int
{
    return \defined('TCP_KEEPIDLE') ? \TCP_KEEPIDLE : \TCP_KEEPALIVE; // macOS names the idle option TCP_KEEPALIVE
}

/** @param resource $stream */
function readSocketOption($stream, int $level, int $option): int
{
    $socket = \socket_import_stream($stream);
    expect($socket)->not->toBeFalse();

    return (int) \socket_get_option($socket, $level, $option);
}

/** @return array{resource, string} listener and its address, created from the BindContext */
function listenWith(BindContext $context): array
{
    $server = \stream_socket_server(
        'tcp://127.0.0.1:0',
        $errno,
        $error,
        \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        \stream_context_create($context->toStreamContextArray()),
    );
    expect($server)->not->toBeFalse();

    return [$server, \stream_socket_get_name($server, false)];
}

describe('ConnectContext socket options', function () {
    it('emits nothing extra by default', function () {
        expect((new ConnectContext())->toStreamContextArray()['socket'])->toBe(['tcp_nodelay' => false]);
    });

    it('emits keep-alive values only when given', function () {
        $context = new ConnectContext();

        expect($context->withKeepAlive()->toStreamContextArray()['socket'])
            ->toBe(['tcp_nodelay' => false, 'so_keepalive' => true]);

        expect($context->withKeepAlive(60, 10, 3)->toStreamContextArray()['socket'])->toBe([
            'tcp_nodelay' => false,
            'so_keepalive' => true,
            'tcp_keepidle' => 60,
            'tcp_keepintvl' => 10,
            'tcp_keepcnt' => 3,
        ]);

        expect($context->withKeepAlive(60)->toStreamContextArray()['socket'])->not->toHaveKeys(['tcp_keepintvl', 'tcp_keepcnt']);
    });

    it('is immutable and can drop keep-alive again', function () {
        $base = new ConnectContext();
        $on = $base->withKeepAlive(60, 10, 3);

        expect($base->hasKeepAlive())->toBeFalse()
            ->and($on->hasKeepAlive())->toBeTrue()
            ->and($on->getKeepAliveIdle())->toBe(60)
            ->and($on->getKeepAliveInterval())->toBe(10)
            ->and($on->getKeepAliveCount())->toBe(3);

        $off = $on->withoutKeepAlive();
        expect($off->hasKeepAlive())->toBeFalse()
            ->and($off->getKeepAliveIdle())->toBeNull()
            ->and($off->toStreamContextArray()['socket'])->not->toHaveKey('so_keepalive');
    });

    it('emits buffer sizes and can drop them', function () {
        $context = (new ConnectContext())->withReceiveBufferSize(131072)->withSendBufferSize(65536);

        expect($context->getReceiveBufferSize())->toBe(131072)
            ->and($context->getSendBufferSize())->toBe(65536)
            ->and($context->toStreamContextArray()['socket'])->toMatchArray(['so_rcvbuf' => 131072, 'so_sndbuf' => 65536]);

        $cleared = $context->withoutReceiveBufferSize()->withoutSendBufferSize();
        expect($cleared->toStreamContextArray()['socket'])->not->toHaveKeys(['so_rcvbuf', 'so_sndbuf']);
    });

    it('rejects non-positive values', function () {
        expect(fn () => (new ConnectContext())->withKeepAlive(0))->toThrow(ValueError::class)
            ->and(fn () => (new ConnectContext())->withKeepAlive(null, -1))->toThrow(ValueError::class)
            ->and(fn () => (new ConnectContext())->withReceiveBufferSize(0))->toThrow(ValueError::class)
            ->and(fn () => (new ConnectContext())->withSendBufferSize(0))->toThrow(ValueError::class);
    });
});

describe('BindContext socket options', function () {
    it('emits keep-alive and buffer options like ConnectContext', function () {
        $socket = (new BindContext())
            ->withKeepAlive(60, 10, 3)
            ->withReceiveBufferSize(131072)
            ->withSendBufferSize(65536)
            ->toStreamContextArray()['socket'];

        expect($socket)->toMatchArray([
            'so_keepalive' => true,
            'tcp_keepidle' => 60,
            'tcp_keepintvl' => 10,
            'tcp_keepcnt' => 3,
            'so_rcvbuf' => 131072,
            'so_sndbuf' => 65536,
        ]);

        expect((new BindContext())->toStreamContextArray()['socket'])
            ->not->toHaveKeys(['so_keepalive', 'so_rcvbuf', 'so_sndbuf']);
    });

    it('is immutable and validates', function () {
        $base = new BindContext();
        $on = $base->withKeepAlive(30);

        expect($base->hasKeepAlive())->toBeFalse()
            ->and($on->hasKeepAlive())->toBeTrue()
            ->and($on->withoutKeepAlive()->hasKeepAlive())->toBeFalse()
            ->and(fn () => $base->withKeepAlive(0))->toThrow(ValueError::class)
            ->and(fn () => $base->withSendBufferSize(0))->toThrow(ValueError::class);
    });
});

describe('live sockets', function () {
    beforeEach(function () {
        if (!\extension_loaded('sockets')) {
            $this->markTestSkipped('ext-sockets is needed to read socket options back from the OS');
        }
    });

    it('applies keep-alive to a client connect', function () {
        [$server, $address] = listenWith(new BindContext());

        $plain = \stream_socket_client("tcp://{$address}");
        expect(readSocketOption($plain, \SOL_SOCKET, \SO_KEEPALIVE))->toBe(0);

        $client = \stream_socket_client(
            "tcp://{$address}",
            $errno,
            $error,
            5,
            \STREAM_CLIENT_CONNECT,
            \stream_context_create((new ConnectContext())->withKeepAlive(77, 11, 5)->toStreamContextArray()),
        );

        expect(readSocketOption($client, \SOL_SOCKET, \SO_KEEPALIVE))->not->toBe(0)
            ->and(readSocketOption($client, \SOL_TCP, socketOptionKeepIdle()))->toBe(77)
            ->and(readSocketOption($client, \SOL_TCP, \TCP_KEEPINTVL))->toBe(11)
            ->and(readSocketOption($client, \SOL_TCP, \TCP_KEEPCNT))->toBe(5);
    });

    it('applies keep-alive through the socket connector', function () {
        [$server, $address] = listenWith(new BindContext());

        $socket = (new DnsSocketConnector())->connect("tcp://{$address}", (new ConnectContext())->withKeepAlive());

        expect($socket)->toBeInstanceOf(ResourceStream::class)
            ->and(readSocketOption($socket->getResource(), \SOL_SOCKET, \SO_KEEPALIVE))->not->toBe(0);

        $socket->close();
    });

    it('applies buffer sizes to a client connect', function () {
        [$server, $address] = listenWith(new BindContext());

        // The OS rounds or doubles the request, so compare against the default instead of the exact value
        $client = \stream_socket_client(
            "tcp://{$address}",
            $errno,
            $error,
            5,
            \STREAM_CLIENT_CONNECT,
            \stream_context_create((new ConnectContext())->withReceiveBufferSize(1048576)->withSendBufferSize(1048576)->toStreamContextArray()),
        );

        expect(readSocketOption($client, \SOL_SOCKET, \SO_RCVBUF))->toBeGreaterThanOrEqual(1048576)
            ->and(readSocketOption($client, \SOL_SOCKET, \SO_SNDBUF))->toBeGreaterThanOrEqual(1048576);
    });

    it('applies tcp_nodelay to an accepted socket from a listener-level option', function () {
        [$server, $address] = listenWith((new BindContext())->withTcpNoDelay());
        $client = \stream_socket_client("tcp://{$address}");
        $accepted = \stream_socket_accept($server, 5);

        expect(readSocketOption($accepted, \SOL_TCP, \TCP_NODELAY))->not->toBe(0);

        [$plainServer, $plainAddress] = listenWith(new BindContext());
        $plainClient = \stream_socket_client("tcp://{$plainAddress}");
        $plainAccepted = \stream_socket_accept($plainServer, 5);

        expect(readSocketOption($plainAccepted, \SOL_TCP, \TCP_NODELAY))->toBe(0);
    });

    it('applies keep-alive and buffers to the listener and its accepted sockets', function () {
        [$server, $address] = listenWith(
            (new BindContext())->withKeepAlive(77, 11, 5)->withReceiveBufferSize(1048576)->withSendBufferSize(1048576),
        );

        expect(readSocketOption($server, \SOL_SOCKET, \SO_KEEPALIVE))->not->toBe(0)
            ->and(readSocketOption($server, \SOL_SOCKET, \SO_RCVBUF))->toBeGreaterThanOrEqual(1048576);

        $client = \stream_socket_client("tcp://{$address}");
        $accepted = \stream_socket_accept($server, 5);

        expect(readSocketOption($accepted, \SOL_SOCKET, \SO_KEEPALIVE))->not->toBe(0)
            ->and(readSocketOption($accepted, \SOL_TCP, socketOptionKeepIdle()))->toBe(77)
            ->and(readSocketOption($accepted, \SOL_TCP, \TCP_KEEPINTVL))->toBe(11)
            ->and(readSocketOption($accepted, \SOL_TCP, \TCP_KEEPCNT))->toBe(5)
            ->and(readSocketOption($accepted, \SOL_SOCKET, \SO_SNDBUF))->toBeGreaterThanOrEqual(1048576);
    });
});
