<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Revolt\EventLoop;

const RSA_TEST_SCRAMBLE = 'abcdefghijklmnopqrst';
const RSA_TEST_LENENC = 0x00200000; // CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA

function rsaKeyPacket(string $payload, int $seq): string
{
    return substr(pack('V', strlen($payload)), 0, 3).chr($seq & 0xff).$payload;
}

/**
 * A MySQL v10 greeting for caching_sha2_password.
 */
function rsaKeyGreeting(string $plugin = 'caching_sha2_password', int $extraCapabilities = 0): string
{
    $capabilities = 0x00000200 | 0x00008000 | 0x00080000 | $extraCapabilities; // PROTOCOL_41 | SECURE_CONNECTION | PLUGIN_AUTH

    $payload = "\x0a"
        ."8.0.40\0"
        .pack('V', 1)
        .substr(RSA_TEST_SCRAMBLE, 0, 8)
        ."\0"
        .pack('v', $capabilities & 0xffff)
        ."\x21"
        .pack('v', 0x0002)
        .pack('v', $capabilities >> 16)
        .chr(21)
        .str_repeat("\0", 10)
        .substr(RSA_TEST_SCRAMBLE, 8)."\0"
        ."$plugin\0";

    return rsaKeyPacket($payload, 0);
}

/**
 * Answers the handshake response with "fast auth failed" over plain TCP, records the client's next
 * packet and ends the attempt with an ERR.
 *
 * @return list<string> every payload the client sent
 */
function connectToFullAuthServer(Closure $config, ?Throwable &$error = null, string $plugin = 'caching_sha2_password'): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $packets = [];
    $watchers = [];

    $watchers[] = EventLoop::onReadable($server, static function (string $id, $server) use (&$packets, &$watchers, $plugin): void {
        EventLoop::cancel($id);
        $conn = stream_socket_accept($server, 0);
        stream_set_blocking($conn, false);
        fwrite($conn, rsaKeyGreeting($plugin));

        $watchers[] = EventLoop::onReadable($conn, static function (string $id, $conn) use (&$packets): void {
            $chunk = fread($conn, 65536);
            if ($chunk === '' || $chunk === false) {
                EventLoop::cancel($id);
                fclose($conn);

                return;
            }

            $seq = ord($chunk[3]);
            $packets[] = substr($chunk, 4);

            if (count($packets) === 1) {
                fwrite($conn, rsaKeyPacket("\x01\x04", $seq + 1)); // AuthMoreData: fast auth failed
            } else {
                fwrite($conn, rsaKeyPacket("\xff".pack('v', 1045).'#28000Access denied', $seq + 1));
            }
        });
    });

    try {
        (new SocketMysqlConnector)->connect($config($port));
    } catch (Throwable $e) {
        // The scripted server always ends with an ERR.
        $error = $e;
    } finally {
        foreach ($watchers as $watcher) {
            EventLoop::cancel($watcher);
        }
        fclose($server);
    }

    return $packets;
}

/**
 * @return array{0: OpenSSLAsymmetricKey, 1: string} a private key and its public PEM
 */
function rsaTestKeyPair(): array
{
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    return [$private, openssl_pkey_get_details($private)['key']];
}

function rsaDecryptsTo(string $block, OpenSSLAsymmetricKey $private, string $password): bool
{
    $expected = "$password\0" ^ str_repeat(RSA_TEST_SCRAMBLE, intdiv(strlen($password) + 1, 20) + 1);

    return openssl_private_decrypt($block, $plain, $private, OPENSSL_PKCS1_OAEP_PADDING) && $plain === $expected;
}

/**
 * Runs a scripted auth server: the n-th client payload (0 = handshake response) is answered with
 * $replies[n] (null: no reply), anything past the script gets an ERR, COM_QUIT closes. A successful
 * connection is closed again.
 *
 * @param list<?string> $replies
 * @return list<string> every payload the client sent
 */
function connectToScriptedAuthServer(
    Closure $config,
    array $replies,
    string $plugin,
    ?Throwable &$error = null,
    int $extraCapabilities = RSA_TEST_LENENC,
): array {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $packets = [];
    $watchers = [];

    $watchers[] = EventLoop::onReadable($server, static function (string $id, $server) use (&$packets, &$watchers, $replies, $plugin, $extraCapabilities): void {
        EventLoop::cancel($id);
        $conn = stream_socket_accept($server, 0);
        stream_set_blocking($conn, false);
        fwrite($conn, rsaKeyGreeting($plugin, $extraCapabilities));
        $buffer = '';

        $watchers[] = EventLoop::onReadable($conn, static function (string $id, $conn) use (&$packets, &$buffer, $replies): void {
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

                if ($payload === "\x01" && $seq === 0) {
                    EventLoop::cancel($id);
                    fclose($conn);

                    return;
                }

                $index = count($packets);
                $packets[] = $payload;

                $reply = array_key_exists($index, $replies)
                    ? $replies[$index]
                    : "\xff".pack('v', 1045).'#28000Access denied';

                if ($reply !== null) {
                    fwrite($conn, rsaKeyPacket($reply, $seq + 1));
                }
            }
        });
    });

    try {
        $connection = (new SocketMysqlConnector)->connect($config($port), new TimeoutCancellation(5));
        $connection->close();
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        foreach ($watchers as $watcher) {
            EventLoop::cancel($watcher);
        }
        fclose($server);
    }

    return $packets;
}

function rsaScriptConfig(int $port): MysqlConfig
{
    return new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2', charset: ''); // no SET NAMES after auth
}

const RSA_TEST_OK = "\x00\x00\x00\x02\x00\x00\x00";

it('encrypts the password with a pinned server key instead of asking the server for one', function (string $password) {
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $publicPem = openssl_pkey_get_details($private)['key'];

    $packets = connectToFullAuthServer(
        static fn (int $port) => (new MysqlConfig('127.0.0.1', $port, 'app', $password))->withKey($publicPem),
    );

    expect($packets)->toHaveCount(2)
        ->and($packets[1])->not->toBe("\x02")
        ->and($packets[1])->not->toContain($password);

    // Protocol (and mysqlnd_xor_string): password plus NUL, XORed with the 20-byte nonce repeated.
    $expected = "$password\0" ^ str_repeat(RSA_TEST_SCRAMBLE, intdiv(strlen($password) + 1, 20) + 1);

    expect(openssl_private_decrypt($packets[1], $plain, $private, OPENSSL_PKCS1_OAEP_PADDING))->toBeTrue()
        ->and(bin2hex($plain))->toBe(bin2hex($expected));
})->with([
    'short' => 'hunter2',
    'exactly 20 characters' => 'abcdefghij0123456789',
    'longer than the nonce' => 'correct-horse-battery-staple',
    'more than twice the nonce' => str_repeat('pa55w0rd-', 5),
]);

it('asks the server for its key over plain TCP when none is pinned, as pdo_mysql (mysqlnd) does', function () {
    $packets = connectToFullAuthServer(
        static fn (int $port) => new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2'),
    );

    expect($packets)->toHaveCount(2)
        ->and($packets[1])->toBe("\x02")
        ->and(implode('', $packets))->not->toContain('hunter2');
});

it('still asks the server for its key when secure key retrieval is explicitly off', function () {
    $packets = connectToFullAuthServer(
        static fn (int $port) => (new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2'))->withSecureKeyRetrieval(false),
    );

    expect($packets)->toHaveCount(2)
        ->and($packets[1])->toBe("\x02");
});

it('refuses caching_sha2 full auth over plain TCP without a pinned key when secure key retrieval is on', function () {
    $packets = connectToFullAuthServer(
        static fn (int $port) => (new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2'))->withSecureKeyRetrieval(),
        $error,
    );

    // Only the handshake response (a SHA-256 scramble) went out: no key request, no password.
    expect($packets)->toHaveCount(1)
        ->and($packets)->not->toContain("\x02")
        ->and(implode('', $packets))->not->toContain('hunter2')
        ->and($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getMessage())->toContain('plaintext connection')
        ->and($error->getMessage())->toContain('TLS')
        ->and($error->getMessage())->toContain('withKey()')
        ->and($error->getMessage())->toContain('secure key retrieval off');
});

it('refuses sha256_password over plain TCP without a pinned key when secure key retrieval is on', function () {
    $packets = connectToFullAuthServer(
        static fn (int $port) => (new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2'))->withSecureKeyRetrieval(),
        $error,
        'sha256_password',
    );

    // The handshake response would have carried the 0x01 key request; nothing is sent at all.
    expect($packets)->toBe([])
        ->and($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getMessage())->toContain('sha256_password over a plaintext connection');
});

it('still authenticates with a pinned key when secure key retrieval is on', function () {
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $publicPem = openssl_pkey_get_details($private)['key'];

    $packets = connectToFullAuthServer(
        static fn (int $port) => (new MysqlConfig('127.0.0.1', $port, 'app', 'hunter2'))
            ->withKey($publicPem)
            ->withSecureKeyRetrieval(),
    );

    expect($packets)->toHaveCount(2)
        ->and(openssl_private_decrypt($packets[1], $plain, $private, OPENSSL_PKCS1_OAEP_PADDING))->toBeTrue()
        ->and(bin2hex($plain))->toBe(bin2hex("hunter2\0" ^ RSA_TEST_SCRAMBLE));
});

it('turns secure key retrieval on from the Laravel connection config', function () {
    $connector = new class extends FledgeMySqlConnector
    {
        public function config(array $config): MysqlConfig
        {
            return $this->buildConfig($config);
        }
    };

    expect($connector->config(['host' => '127.0.0.1'])->isSecureKeyRetrievalEnabled())->toBeFalse()
        ->and($connector->config(['host' => '127.0.0.1', 'secure_key_retrieval' => true])->isSecureKeyRetrievalEnabled())->toBeTrue();
});

it('refuses a caching_sha2 server key that the client never asked for', function (string $mode) {
    [, $pinnedPem] = rsaTestKeyPair();
    [, $attackerPem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => match ($mode) {
            'default' => rsaScriptConfig($port),
            'strict' => rsaScriptConfig($port)->withSecureKeyRetrieval(),
            'pinned key' => rsaScriptConfig($port)->withKey($pinnedPem),
        },
        ["\x01".$attackerPem], // skips "perform full authentication" and pushes a key at once
        'caching_sha2_password',
        $error,
    );

    // Only the handshake response (a SHA-256 scramble) went out: no RSA block, no password.
    expect($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getMessage())->toContain('not requested')
        ->and($packets)->toHaveCount(1)
        ->and(implode('', $packets))->not->toContain('hunter2');
})->with(['default', 'strict', 'pinned key']);

it('never re-encrypts with a key pushed after the pinned key was used for caching_sha2', function () {
    [$pinnedPrivate, $pinnedPem] = rsaTestKeyPair();
    [, $attackerPem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => rsaScriptConfig($port)->withKey($pinnedPem),
        ["\x01\x04", "\x01".$attackerPem],
        'caching_sha2_password',
        $error,
    );

    expect($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($packets)->toHaveCount(2)
        ->and(rsaDecryptsTo($packets[1], $pinnedPrivate, 'hunter2'))->toBeTrue();
});

it('still fetches the key over plain TCP when asked for it and none is pinned', function () {
    [$serverPrivate, $serverPem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => rsaScriptConfig($port),
        ["\x01\x04", "\x01".$serverPem, RSA_TEST_OK],
        'caching_sha2_password',
        $error,
    );

    expect($error)->toBeNull()
        ->and($packets)->toHaveCount(3)
        ->and($packets[1])->toBe("\x02")
        ->and(rsaDecryptsTo($packets[2], $serverPrivate, 'hunter2'))->toBeTrue();
});

it('never replaces a pinned sha256_password key with one the server sends', function () {
    [, $pinnedPem] = rsaTestKeyPair();
    [, $attackerPem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => rsaScriptConfig($port)->withKey($pinnedPem),
        ["\x01".$attackerPem],
        'sha256_password',
        $error,
    );

    // The handshake response (encrypted with the pinned key) is all the server gets.
    expect($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getMessage())->toContain('not requested')
        ->and($packets)->toHaveCount(1);
});

it('still accepts a sha256_password key it asked for when none is pinned', function () {
    [, $serverPem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => rsaScriptConfig($port),
        ["\x01".$serverPem, RSA_TEST_OK],
        'sha256_password',
        $error,
    );

    expect($error)->toBeNull()
        ->and($packets)->toHaveCount(2)
        ->and(implode('', $packets))->not->toContain('hunter2');
});

/**
 * The auth response of a HandshakeResponse41 for user "app": after 32 fixed bytes and "app\0".
 *
 * @return array{0: string, 1: string} the length prefix and the auth response
 */
function rsaHandshakeAuth(string $payload): array
{
    $offset = 36;
    $first = ord($payload[$offset]);

    [$prefixLength, $length] = match (true) {
        $first < 0xfb => [1, $first],
        $first === 0xfc => [3, unpack('v', substr($payload, $offset + 1, 2))[1]],
        $first === 0xfd => [4, unpack('V', substr($payload, $offset + 1, 3)."\0")[1]],
    };

    return [substr($payload, $offset, $prefixLength), substr($payload, $offset + $prefixLength, $length)];
}

it('sends the full 2048-bit sha256_password block with a length-encoded prefix', function (bool $pinned) {
    [$private, $pem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => $pinned ? rsaScriptConfig($port)->withKey($pem) : rsaScriptConfig($port),
        $pinned ? [RSA_TEST_OK] : ["\x01".$pem, RSA_TEST_OK],
        'sha256_password',
        $error,
    );

    [$prefix, $block] = rsaHandshakeAuth(end($packets));

    expect($error)->toBeNull()
        ->and(bin2hex($prefix))->toBe('fc0001')
        ->and(strlen($block))->toBe(256)
        ->and(rsaDecryptsTo($block, $private, 'hunter2'))->toBeTrue();
})->with(['pinned key' => true, 'fetched key' => false]);

it('fails instead of sending a mis-framed RSA block to a server without length-encoded auth data', function () {
    [, $pem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => rsaScriptConfig($port)->withKey($pem),
        [RSA_TEST_OK],
        'sha256_password',
        $error,
        0,
    );

    expect($packets)->toBe([])
        ->and($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getMessage())->toContain('256 bytes');
});

it('refuses an unrequested key after an auth switch to caching_sha2', function () {
    [, $attackerPem] = rsaTestKeyPair();

    $packets = connectToScriptedAuthServer(
        static fn (int $port) => rsaScriptConfig($port),
        ["\xfecaching_sha2_password\0".RSA_TEST_SCRAMBLE."\0", "\x01".$attackerPem],
        'mysql_native_password',
        $error,
    );

    expect($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getMessage())->toContain('not requested')
        ->and($packets)->toHaveCount(2);
});
