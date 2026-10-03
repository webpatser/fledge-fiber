<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Stream\ResourceStream;
use Tests\Fledge\database\Stubs\CapturingSocketConnector;

use function Fledge\Async\Stream\socketConnector;

uses()->beforeEach(function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
    }

    if (! function_exists('socket_import_stream')) {
        $this->markTestSkipped('ext-sockets is required to read TCP_NODELAY back from the socket');
    }
});

it('sets TCP_NODELAY on the live mysql tcp socket', function () {
    $config = mysqlConfig();
    $capturing = new CapturingSocketConnector(socketConnector());

    $connection = (new SocketMysqlConnector($capturing))->connect(new MysqlConfig(
        host: $config['host'],
        port: $config['port'],
        user: $config['username'],
        password: $config['password'],
        database: $config['database'],
    ));

    $row = $connection->query('SELECT 1 AS one')->fetchRow();

    expect((int) $row['one'])->toBe(1)
        ->and($capturing->socket)->toBeInstanceOf(ResourceStream::class);

    $socket = socket_import_stream($capturing->socket->getResource());

    expect($socket)->toBeInstanceOf(Socket::class)
        ->and(socket_get_option($socket, SOL_TCP, TCP_NODELAY))->toBeGreaterThan(0);

    $connection->close();
});
