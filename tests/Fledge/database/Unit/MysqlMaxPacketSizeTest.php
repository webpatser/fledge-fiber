<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Revolt\EventLoop;

/**
 * Connect to a throwaway server that announces an oversized packet and then trickles a few
 * bytes, as a malicious server would to make the client buffer an unbounded payload.
 */
function connectToOversizedPacketServer(int $announcedLength, int $maxPacketSize): Throwable
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $watcher = EventLoop::onReadable($server, static function (string $id, $server) use ($announcedLength): void {
        EventLoop::cancel($id);
        $client = stream_socket_accept($server, 0);
        fwrite($client, substr(pack('V', $announcedLength), 0, 3)."\x00".str_repeat('x', 64));
        fclose($client);
    });

    try {
        (new SocketMysqlConnector)->connect(
            (new MysqlConfig('127.0.0.1', $port))->withMaxPacketSize($maxPacketSize),
        );
    } catch (Throwable $e) {
        return $e;
    } finally {
        EventLoop::cancel($watcher);
        fclose($server);
    }

    throw new RuntimeException('Expected the connect to fail');
}

function exceptionChainMessages(Throwable $e): string
{
    $messages = '';
    for (; $e !== null; $e = $e->getPrevious()) {
        $messages .= $e->getMessage()."\n";
    }

    return $messages;
}

it('rejects a payload larger than the configured maximum before buffering it', function (int $announcedLength) {
    $e = connectToOversizedPacketServer($announcedLength, 1024);

    expect($e)->toBeInstanceOf(SqlConnectionException::class)
        ->and(exceptionChainMessages($e))->toContain('larger than the configured maximum of 1024 bytes');
})->with([
    'multi-packet continuation' => [0xffffff],
    'single packet' => [2048],
]);

it('defaults the maximum packet size to 1 GiB', function () {
    expect((new MysqlConfig('127.0.0.1'))->getMaxPacketSize())->toBe(1024 * 1024 * 1024)
        ->and((new MysqlConfig('127.0.0.1'))->withMaxPacketSize(4096)->getMaxPacketSize())->toBe(4096);
});

it('rejects a non-positive maximum packet size', function () {
    (new MysqlConfig('127.0.0.1'))->withMaxPacketSize(0);
})->throws(ValueError::class);
