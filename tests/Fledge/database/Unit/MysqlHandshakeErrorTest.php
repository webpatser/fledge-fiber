<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Fiber\Database\Pdo\FledgePdoException;
use Revolt\EventLoop;

/**
 * Connect to a throwaway local server that answers with a MySQL ERR packet instead of the
 * handshake (as a real server does for 1040 Too many connections or 1129 Host blocked).
 */
function connectToErrPacketServer(int $errno, string $message): SqlConnectionException
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $payload = "\xff".pack('v', $errno).$message;
    $packet = substr(pack('V', strlen($payload)), 0, 3)."\x00".$payload;

    $watcher = EventLoop::onReadable($server, static function (string $id, $server) use ($packet): void {
        EventLoop::cancel($id);
        $client = stream_socket_accept($server, 0);
        fwrite($client, $packet);
        fclose($client);
    });

    try {
        (new SocketMysqlConnector)->connect(new MysqlConfig('127.0.0.1', $port));
    } catch (SqlConnectionException $e) {
        return $e;
    } finally {
        EventLoop::cancel($watcher);
        fclose($server);
    }

    throw new RuntimeException('Expected the handshake to fail');
}

it('carries the server error number of a handshake ERR packet as the exception code', function (int $errno, string $message) {
    $e = connectToErrPacketServer($errno, $message);

    expect($e->getCode())->toBe($errno)
        ->and($e->getMessage())->toEndWith($message);
})->with([
    'too many connections' => [1040, 'Too many connections'],
    'host blocked' => [1129, "Host '10.0.0.5' is blocked because of many connection errors"],
]);

it('shapes the handshake error from its code, not its text', function () {
    $e = FledgePdoException::fromThrowable(connectToErrPacketServer(1130, 'Server says no'), 'mysql');

    expect($e->getCode())->toBe(1130)
        ->and($e->getMessage())->toBe('SQLSTATE[HY000] [1130] Server says no')
        ->and($e->errorInfo)->toBe(['HY000', 1130, 'Server says no']);
});

it('prefers the exception code and falls back to the message text', function (int $code, string $message, int $expected) {
    $error = new SqlConnectionException("Could not connect to tcp://127.0.0.1:3306: {$message}", $code);

    expect(FledgePdoException::fromThrowable($error, 'mysql')->getCode())->toBe($expected);
})->with([
    'code wins' => [1044, '#42000Access denied for user', 1044],
    'unknown database by code' => [1049, 'something else', 1049],
    'fallback 1045' => [0, "#28000Access denied for user 'x'@'%' (using password: YES)", 1045],
    'fallback 1049' => [0, "#42000Unknown database 'nope'", 1049],
]);
