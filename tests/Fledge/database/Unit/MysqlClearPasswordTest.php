<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Revolt\EventLoop;

/**
 * A MySQL v10 greeting that asks for the mysql_clear_password auth plugin, as a rogue
 * server or a man in the middle would to harvest the password.
 */
function clearPasswordGreeting(): string
{
    $capabilities = 0x00000200 | 0x00008000 | 0x00080000; // PROTOCOL_41 | SECURE_CONNECTION | PLUGIN_AUTH

    $payload = "\x0a"
        ."8.0.40\0"
        .pack('V', 1)
        .'abcdefgh'
        ."\0"
        .pack('v', $capabilities & 0xffff)
        ."\x21"
        .pack('v', 0x0002)
        .pack('v', $capabilities >> 16)
        .chr(21)
        .str_repeat("\0", 10)
        ."ijklmnopqrst\0"
        ."mysql_clear_password\0";

    return substr(pack('V', strlen($payload)), 0, 3)."\x00".$payload;
}

/**
 * Serve the greeting on $server, record everything the client sends and answer the first
 * client packet with an ERR so the connect attempt always ends.
 *
 * @return array{0: ?Throwable, 1: string}
 */
function connectToClearPasswordServer($server, MysqlConfig $config): array
{
    $received = '';
    $watchers = [];

    $watchers[] = EventLoop::onReadable($server, static function (string $id, $server) use (&$received, &$watchers): void {
        EventLoop::cancel($id);
        $client = stream_socket_accept($server, 0);
        stream_set_blocking($client, false);
        fwrite($client, clearPasswordGreeting());

        $watchers[] = EventLoop::onReadable($client, static function (string $id, $client) use (&$received): void {
            $chunk = fread($client, 65536);
            if ($chunk === '' || $chunk === false) {
                EventLoop::cancel($id);
                fclose($client);

                return;
            }
            $received .= $chunk;
            $payload = "\xff".pack('v', 1045).'#28000Access denied';
            fwrite($client, substr(pack('V', strlen($payload)), 0, 3)."\x02".$payload);
        });
    });

    $error = null;

    try {
        (new SocketMysqlConnector)->connect($config);
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        foreach ($watchers as $watcher) {
            EventLoop::cancel($watcher);
        }
        fclose($server);
    }

    return [$error, $received];
}

it('refuses mysql_clear_password over plain TCP and never sends the password', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    [$error, $received] = connectToClearPasswordServer(
        $server,
        new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2-secret'),
    );

    expect($error)->toBeInstanceOf(SqlConnectionException::class);

    $messages = '';
    for ($e = $error; $e !== null; $e = $e->getPrevious()) {
        $messages .= $e->getMessage()."\n";
    }

    expect($messages)->toContain('mysql_clear_password')
        ->and($received)->not->toContain('hunter2-secret');
});

it('still answers mysql_clear_password over a unix socket', function () {
    $path = sys_get_temp_dir().'/fledge-mysql-clear-'.bin2hex(random_bytes(4)).'.sock';
    $server = stream_socket_server('unix://'.$path);

    try {
        [, $received] = connectToClearPasswordServer(
            $server,
            new MysqlConfig($path, user: 'app', password: 'hunter2-secret'),
        );
    } finally {
        @unlink($path);
    }

    expect($received)->toContain("hunter2-secret");
});
