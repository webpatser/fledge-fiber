<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Revolt\EventLoop;

/**
 * Connect to a throwaway local server whose handshake greeting does not advertise CLIENT_SSL,
 * as a network attacker stripping the bit would produce. Returns the connection failure and
 * every byte the client sent after the greeting.
 *
 * @return array{0: SqlConnectionException, 1: string}
 */
function connectToServerWithoutClientSsl(?ConnectContext $context): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    // Protocol 10 greeting: version, connection id, 8 bytes auth data, filler, lower capability
    // flags with everything set except CLIENT_SSL (0x0800).
    $payload = "\x0a"."8.0.0-fake\0".pack('V', 1).'abcdefgh'."\0".pack('v', 0xFFFF & ~0x0800);
    $greeting = substr(pack('V', strlen($payload)), 0, 3)."\x00".$payload;

    $received = '';
    $accepted = null;

    $watcher = EventLoop::onReadable($server, static function (string $id, $server) use ($greeting, &$received, &$accepted): void {
        EventLoop::cancel($id);
        $accepted = stream_socket_accept($server, 0);
        fwrite($accepted, $greeting);

        EventLoop::onReadable($accepted, static function (string $id, $client) use (&$received): void {
            EventLoop::cancel($id);
            $received .= (string) fread($client, 65536);
            fclose($client);
        });
    });

    try {
        (new SocketMysqlConnector)->connect(new MysqlConfig('127.0.0.1', $port, 'app', 's3cret-password', null, $context));
    } catch (SqlConnectionException $e) {
        return [$e, $received];
    } finally {
        EventLoop::cancel($watcher);
        fclose($server);
    }

    throw new RuntimeException('Expected the connection to fail');
}

it('refuses to continue in plaintext when TLS is configured but the greeting lacks CLIENT_SSL', function () {
    $context = (new ConnectContext)->withTlsContext((new ClientTlsContext)->withPeerName('127.0.0.1'));

    [$e, $received] = connectToServerWithoutClientSsl($context);

    expect($e->getMessage())->toContain('CLIENT_SSL')
        ->and($received)->not->toContain('app')
        ->and($received)->not->toContain('s3cret-password')
        ->and($received)->toBe('');
});

it('still sends the auth packet in plaintext when no TLS context is configured', function () {
    [, $received] = connectToServerWithoutClientSsl(null);

    expect($received)->toContain("app\0");
});
