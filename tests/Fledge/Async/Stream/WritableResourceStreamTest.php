<?php

declare(strict_types=1);

use Fledge\Async\Stream\StreamException;
use Fledge\Async\Stream\WritableResourceStream;
use Fledge\Async\Test\Fledge\Async\Stream\TlsSocketPair;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;

/*
 * A TLS stream whose peer has gone can report fwrite() === 0 without raising a warning while feof() is true
 * (OpenSSL SSL_ERROR_ZERO_RETURN, upstream amphp/byte-stream#116). The writable watcher then kept unshifting the
 * chunk and returning with the watcher enabled, spinning forever and never resolving the pending write.
 *
 * The OpenSSL build on the dev machine raises "Broken pipe" instead, so the silent case is reproduced through a
 * userspace stream with exactly that PHP-level contract (write returns 0, no warning, eof set), selectable through
 * a real socket pair.
 */
final class ZeroWriteAtEofStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @var resource */
    public static $selectable;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return resource */
    public function stream_cast(int $castAs)
    {
        return self::$selectable;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void {}
}

it('fails a pending write when fwrite writes nothing silently and the peer reached eof', function () {
    [$selectable, $other] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    ZeroWriteAtEofStreamWrapper::$selectable = $selectable;
    stream_wrapper_register('fledge-zero-write', ZeroWriteAtEofStreamWrapper::class);

    try {
        $resource = fopen('fledge-zero-write://peer', 'r+');
        fread($resource, 1); // Sets the stream's eof flag, as the TLS layer does once the peer is gone.
        expect(feof($resource))->toBeTrue();

        $stream = new WritableResourceStream($resource);

        try {
            async(fn () => $stream->write('payload'))->await(new TimeoutCancellation(2));
            $this->fail('Expected StreamException to be thrown.');
        } catch (StreamException $e) {
            expect($e->getMessage())->toBe('Failed to write to stream: the peer closed the connection')
                ->and($stream->isWritable())->toBeFalse();
        } finally {
            $stream->close();
        }
    } finally {
        stream_wrapper_unregister('fledge-zero-write');
        fclose($selectable);
        fclose($other);
    }
});

it('fails a pending write to a tls peer that closed the connection', function () {
    [$client, $server] = TlsSocketPair::create();
    fclose($server);
    usleep(20000);

    $stream = new WritableResourceStream($client);

    try {
        async(function () use ($stream): void {
            for ($i = 0; $i < 10; $i++) {
                $stream->write(str_repeat('x', 1000));
                Fledge\Async\delay(0.01);
            }
        })->await(new TimeoutCancellation(2));
        $this->fail('Expected StreamException to be thrown.');
    } catch (StreamException $e) {
        expect($stream->isWritable())->toBeFalse();
    } finally {
        $stream->close();
    }
});

it('still writes to a live tls peer', function () {
    [$client, $server] = TlsSocketPair::create();

    $stream = new WritableResourceStream($client);
    $payload = str_repeat('abcdefgh', 64 * 1024); // 512 KiB, more than one socket buffer.

    $reader = async(function () use ($server, $payload): string {
        $received = '';

        while (strlen($received) < strlen($payload)) {
            $chunk = fread($server, 65536);
            if ($chunk === '' || $chunk === false) {
                Fledge\Async\delay(0.001);
                continue;
            }
            $received .= $chunk;
        }

        return $received;
    });

    async(fn () => $stream->write($payload))->await(new TimeoutCancellation(5));

    expect($reader->await(new TimeoutCancellation(5)))->toBe($payload);

    $stream->close();
    fclose($server);
});
