<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlException;
use Fledge\Async\File\Driver\BlockingFilesystemDriver;
use Fledge\Async\TimeoutCancellation;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\File\createDefaultDriver;
use function Fledge\Async\File\filesystem;

function localInfilePacket(string $payload, int $seq): string
{
    return substr(pack('V', strlen($payload)), 0, 3).chr($seq & 0xff).$payload;
}

/**
 * A MySQL v10 greeting for mysql_native_password that advertises CLIENT_LOCAL_FILES.
 */
function localInfileGreeting(): string
{
    $capabilities = 0x00000080 | 0x00000200 | 0x00008000 | 0x00080000; // LOCAL_FILES | PROTOCOL_41 | SECURE_CONNECTION | PLUGIN_AUTH

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
        ."mysql_native_password\0";

    return localInfilePacket($payload, 0);
}

function localInfileOk(): string
{
    return "\x00\x00\x00\x02\x00\x00\x00";
}

/**
 * Runs a scripted MySQL server: the handshake response gets an OK, the first query gets a LOCAL INFILE
 * request for $requestedPath, the empty packet that ends the transfer gets an OK (the worst case: the
 * server accepts whatever arrived), and every later query gets an OK. $client receives the port.
 *
 * @return array{0: mixed, 1: list<string>} what $client returned and every payload the client sent
 */
function withLocalInfileServer(string $requestedPath, Closure $client): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $packets = [];
    $watchers = [];
    $infileRequested = false;

    $watchers[] = EventLoop::onReadable($server, static function (string $id, $server) use (&$packets, &$watchers, &$infileRequested, $requestedPath): void {
        EventLoop::cancel($id);
        $conn = stream_socket_accept($server, 0);
        stream_set_blocking($conn, false);
        fwrite($conn, localInfileGreeting());
        $buffer = '';

        $watchers[] = EventLoop::onReadable($conn, static function (string $id, $conn) use (&$packets, &$buffer, &$infileRequested, $requestedPath): void {
            $chunk = fread($conn, 65536);
            if ($chunk === '' || $chunk === false) {
                EventLoop::cancel($id);
                fclose($conn);

                return;
            }

            $buffer .= $chunk;

            while (strlen($buffer) >= 4) {
                $length = unpack('V', substr($buffer, 0, 3)."\0")[1];
                if (strlen($buffer) < 4 + $length) {
                    break;
                }

                $seq = ord($buffer[3]);
                $payload = substr($buffer, 4, $length);
                $buffer = substr($buffer, 4 + $length);
                $packets[] = $payload;

                if ($payload === "\x01") {
                    // COM_QUIT: close as a real server does, so the client's close() returns.
                    EventLoop::cancel($id);
                    fclose($conn);

                    return;
                }

                if (count($packets) === 1) {
                    fwrite($conn, localInfilePacket(localInfileOk(), $seq + 1));
                } elseif (str_starts_with($payload, "\x03LOAD DATA") && !$infileRequested) {
                    $infileRequested = true;
                    fwrite($conn, localInfilePacket("\xfb".$requestedPath, $seq + 1));
                } elseif ($payload === '' || str_starts_with($payload, "\x03")) {
                    // End of the file transfer, or any later query.
                    fwrite($conn, localInfilePacket(localInfileOk(), $seq + 1));
                }
                // File chunks get no reply.
            }
        });
    });

    try {
        $result = $client($port);
    } finally {
        foreach ($watchers as $watcher) {
            EventLoop::cancel($watcher);
        }
        fclose($server);
    }

    return [$result, $packets];
}

/**
 * Connects, runs LOAD DATA LOCAL INFILE and then a second query on the same connection, each bounded so a
 * wedged connection fails the test instead of hanging it.
 *
 * @return array{0: ?Throwable, 1: ?Throwable}
 */
function runLocalInfileQueries(MysqlConfig $config): array
{
    $connection = (new SocketMysqlConnector)->connect($config);

    $errors = [];
    foreach (["LOAD DATA LOCAL INFILE 'x' INTO TABLE t", 'DO 1'] as $sql) {
        try {
            async(static fn () => $connection->query($sql))->await(new TimeoutCancellation(2));
            $errors[] = null;
        } catch (Throwable $e) {
            $errors[] = $e;
        }
    }

    $connection->close();

    return $errors;
}

function localInfileConfig(int $port): MysqlConfig
{
    return new MysqlConfig('127.0.0.1', $port, 'app', 'secret', charset: '');
}

beforeEach(function () {
    // Without ext-uv or ext-eio the default is ParallelFilesystemDriver, which runs file I/O in worker
    // processes. This file tests the MySQL protocol, so the blocking driver keeps it deterministic and
    // free of worker processes.
    filesystem(new BlockingFilesystemDriver);

    $this->root = sys_get_temp_dir().'/fledge-mysql-infile-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/allowed', 0700, true);
    mkdir($this->root.'/outside', 0700, true);
    file_put_contents($this->root.'/allowed/rows.csv', "1,allowed-row\n");
    file_put_contents($this->root.'/outside/secret.txt', 'TOP-SECRET-CONTENT');
    symlink($this->root.'/outside/secret.txt', $this->root.'/allowed/link.txt');
});

afterEach(function () {
    filesystem(createDefaultDriver());

    @unlink($this->root.'/allowed/link.txt');
    @unlink($this->root.'/allowed/rows.csv');
    @unlink($this->root.'/outside/secret.txt');
    @rmdir($this->root.'/allowed');
    @rmdir($this->root.'/outside');
    @rmdir($this->root);
});

it('refuses LOCAL INFILE by default but ends the transfer so the connection stays usable', function () {
    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $this->root.'/outside/secret.txt',
        static fn (int $port) => runLocalInfileQueries(localInfileConfig($port)),
    );

    expect($loadError)->toBeInstanceOf(SqlException::class)
        ->and($loadError->getMessage())->toStartWith('LOAD DATA LOCAL INFILE is forbidden')
        ->and($loadError->getCode())->toBe(2068)
        ->and($packets)->toContain('')
        ->and(implode('', $packets))->not->toContain('TOP-SECRET-CONTENT')
        ->and($nextError)->toBeNull();
});

it('only sends files inside the local infile directory when withLocalInfile() is off', function (string $requested) {
    $requested = str_replace('{root}', $this->root, $requested);
    $allowed = $this->root.'/allowed';

    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $requested,
        static fn (int $port) => runLocalInfileQueries(localInfileConfig($port)->withLocalInfileDirectory($allowed)),
    );

    expect($loadError)->toBeInstanceOf(SqlException::class)
        ->and($loadError->getMessage())->toBe('LOAD DATA LOCAL INFILE DIRECTORY restriction in effect. Unable to open file')
        ->and($loadError->getCode())->toBe(2068)
        ->and($packets)->toContain('')
        ->and(implode('', $packets))->not->toContain('TOP-SECRET-CONTENT')
        ->and($nextError)->toBeNull();
})->with([
    'absolute path outside' => '{root}/outside/secret.txt',
    'path traversal' => '{root}/allowed/../outside/secret.txt',
    'symlink pointing outside' => '{root}/allowed/link.txt',
    'missing file' => '{root}/allowed/missing.csv',
]);

it('sends a file inside the local infile directory without withLocalInfile()', function () {
    $allowed = $this->root.'/allowed';

    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $allowed.'/rows.csv',
        static fn (int $port) => runLocalInfileQueries(localInfileConfig($port)->withLocalInfileDirectory($allowed)),
    );

    expect($loadError)->toBeNull()
        ->and($nextError)->toBeNull()
        ->and(implode('', $packets))->toContain('1,allowed-row')
        ->and($packets)->toContain('');
});

it('still sends any file when only withLocalInfile() is set, as pdo_mysql does', function () {
    [[$loadError], $packets] = withLocalInfileServer(
        $this->root.'/outside/secret.txt',
        static fn (int $port) => runLocalInfileQueries(localInfileConfig($port)->withLocalInfile()),
    );

    expect($loadError)->toBeNull()
        ->and(implode('', $packets))->toContain('TOP-SECRET-CONTENT');
});

it('ignores the local infile directory when withLocalInfile() is set, as mysqlnd does', function () {
    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $this->root.'/outside/secret.txt',
        fn (int $port) => runLocalInfileQueries(
            localInfileConfig($port)->withLocalInfile()->withLocalInfileDirectory($this->root.'/allowed'),
        ),
    );

    expect($loadError)->toBeNull()
        ->and($nextError)->toBeNull()
        ->and(implode('', $packets))->toContain('TOP-SECRET-CONTENT');
});

it('refuses LOCAL INFILE when the configured directory cannot be opened, even with withLocalInfile()', function (bool $enabled) {
    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $this->root.'/allowed/rows.csv',
        function (int $port) use ($enabled) {
            $config = localInfileConfig($port)->withLocalInfileDirectory($this->root.'/no-such-directory');

            return runLocalInfileQueries($enabled ? $config->withLocalInfile() : $config);
        },
    );

    expect($loadError)->toBeInstanceOf(SqlException::class)
        ->and($loadError->getMessage())->toBe('cannot open local_infile_directory')
        ->and($loadError->getCode())->toBe(2068)
        ->and(implode('', $packets))->not->toContain('1,allowed-row')
        ->and($packets)->toContain('')
        ->and($nextError)->toBeNull();
})->with(['local infile on' => true, 'local infile off' => false]);

it('refuses a file name with a NUL byte like the other refusals', function (bool $enabled, bool $directory) {
    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $this->root."/allowed/rows.csv\0.txt",
        function (int $port) use ($enabled, $directory) {
            $config = localInfileConfig($port);
            $config = $enabled ? $config->withLocalInfile() : $config;

            return runLocalInfileQueries($directory ? $config->withLocalInfileDirectory($this->root.'/allowed') : $config);
        },
    );

    expect($loadError)->toBeInstanceOf(SqlException::class)
        ->and($loadError->getCode())->toBe(2068)
        ->and(implode('', $packets))->not->toContain('1,allowed-row')
        ->and($packets)->toContain('')
        ->and($nextError)->toBeNull();
})->with([
    'local infile on' => [true, false],
    'directory only' => [false, true],
    'both' => [true, true],
]);

it('fails the query and keeps the connection when the requested file cannot be read', function () {
    [[$loadError, $nextError], $packets] = withLocalInfileServer(
        $this->root.'/allowed/missing.csv',
        static fn (int $port) => runLocalInfileQueries(localInfileConfig($port)->withLocalInfile()),
    );

    expect($loadError)->toBeInstanceOf(SqlException::class)
        ->and($loadError->getMessage())->toContain('Failed to transfer a file')
        ->and($packets)->toContain('')
        ->and($nextError)->toBeNull();
});
