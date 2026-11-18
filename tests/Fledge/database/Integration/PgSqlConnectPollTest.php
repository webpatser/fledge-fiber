<?php

use Fledge\Async\CancelledException;
use Fledge\Async\Database\Postgres\PgSqlConnection;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\TimeoutCancellation;

/*
 * While libpq waits for the server (PGSQL_POLLING_READING) the connect loop must not keep a
 * writable watcher on the socket: a connected socket is always writable, so the poll callback
 * would spin and burn a full CPU core for as long as a slow connect lasts.
 */
beforeEach(function () {
    if (! extension_loaded('pgsql')) {
        $this->markTestSkipped('ext-pgsql is required for the PgSqlConnection connect loop');
    }
});

it('does not spin the CPU while the server has not answered yet', function () {
    // The kernel completes the TCP handshake from the backlog; nobody ever answers.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $config = new PostgresConfig('127.0.0.1', $port, 'nobody', 'secret', 'nothing');

    $cpu = static function (): float {
        $u = getrusage();

        return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6
            + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
    };

    $cpuStart = $cpu();
    $wallStart = hrtime(true);
    $error = null;

    try {
        PgSqlConnection::connect($config, new TimeoutCancellation(0.5));
    } catch (CancelledException $e) {
        $error = $e;
    }

    $wall = (hrtime(true) - $wallStart) / 1e9;
    $used = $cpu() - $cpuStart;
    fclose($server);

    expect($error)->toBeInstanceOf(CancelledException::class)
        ->and($wall)->toBeGreaterThan(0.4)->toBeLessThan(5.0)
        ->and($used)->toBeLessThan($wall * 0.3);
});
