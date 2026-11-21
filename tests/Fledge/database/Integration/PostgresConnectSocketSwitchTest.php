<?php

use Fledge\Async\Database\Postgres\PgSqlConnection;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PqConnection;
use Fledge\Async\Stream\ResourceServerSocket;
use Fledge\Async\Stream\Socket;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\Stream\connect;
use function Fledge\Async\Stream\listen;

/*
 * libpq replaces its socket during an asynchronous connect when an attempt fails and it moves to the
 * next address or host. It closes the old socket inside the poll step, and a kqueue or epoll
 * registration made for that descriptor never fires for the new one. The connect must watch the
 * current socket after every step, or it waits until the cancellation (or forever).
 */
beforeEach(function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('The Postgres test server is required');
    }
});

dataset('postgres connection classes', [
    'pgsql' => [PgSqlConnection::class, 'pgsql'],
    'pq' => [PqConnection::class, 'pq'],
]);

function switchTestPostgresConfig(string $host, int $port, ?string $connectionString = null): PostgresConfig
{
    $c = postgresConfig();
    $config = new PostgresConfig($host, $port, $c['username'], $c['password'], $c['database'], sslMode: 'disable');

    if ($connectionString !== null) {
        // PostgresConfig takes one port; a per-host port list only fits the raw connection string.
        (new ReflectionProperty(PostgresConfig::class, 'connectionString'))->setValue($config, $connectionString);
    }

    return $config;
}

/**
 * Relays connections accepted on 127.0.0.1 (IPv4 only) to the Postgres test server.
 *
 * @return array{ResourceServerSocket, \ArrayObject<int, Socket>}
 */
function startIpv4PostgresRelay(): array
{
    $c = postgresConfig();
    $server = listen('127.0.0.1:0');
    $sockets = new ArrayObject();

    $relay = static function (Socket $from, Socket $to): void {
        try {
            while (($chunk = $from->read()) !== null) {
                $to->write($chunk);
            }
        } catch (Throwable) {
            // Either side went away: close both below.
        }

        $from->close();
        $to->close();
    };

    (void) async(static function () use ($server, $sockets, $relay, $c): void {
        while ($client = $server->accept()) {
            try {
                $upstream = connect($c['host'].':'.$c['port']);
            } catch (Throwable) {
                $client->close();

                continue;
            }

            $sockets[] = $client;
            $sockets[] = $upstream;
            (void) async($relay, $client, $upstream);
            (void) async($relay, $upstream, $client);
        }
    });

    return [$server, $sockets];
}

it('connects when the first host of a multi-host string refuses the connection', function (string $class, string $extension) {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not loaded");
    }

    $c = postgresConfig();
    $config = switchTestPostgresConfig($c['host'], $c['port']);
    $connectionString = str_replace(
        ['host='.$c['host'], 'port='.$c['port']],
        ['host=127.0.0.1,'.$c['host'], 'port=1,'.$c['port']],
        $config->getConnectionString(),
    );
    $config = switchTestPostgresConfig($c['host'], $c['port'], $connectionString);

    $connection = $class::connect($config, new TimeoutCancellation(5));

    expect($connection->query('SELECT 1 AS one')->fetchRow())->toBe(['one' => 1]);

    $connection->close();
})->with('postgres connection classes');

it('connects to localhost when only its IPv4 address accepts the connection', function (string $class, string $extension) {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not loaded");
    }

    [$server, $sockets] = startIpv4PostgresRelay();
    $port = $server->getAddress()->getPort();

    // The trigger: localhost resolves to ::1 first, nothing listens there, libpq moves on to 127.0.0.1
    // on a new socket. Where localhost has no IPv6 address this is a plain connect.
    $ipv6 = @stream_socket_client("tcp://[::1]:{$port}", $errno, $errstr, 1);
    expect($ipv6)->toBeFalse();

    try {
        $connection = $class::connect(switchTestPostgresConfig('localhost', $port), new TimeoutCancellation(5));

        expect($connection->query('SELECT 1 AS one')->fetchRow())->toBe(['one' => 1]);

        $connection->close();
    } finally {
        $server->close();

        foreach ($sockets as $socket) {
            $socket->close();
        }
    }
})->with('postgres connection classes');
