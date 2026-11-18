<?php

declare(strict_types=1);

use Fledge\Async\Stream\ReadableResourceStream;
use Fledge\Async\Test\Fledge\Async\Stream\TlsSocketPair;
use Fledge\Async\TimeoutCancellation;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * read() checks feof() before the direct read. On a TLS stream feof() runs SSL_peek(), which can move a record that
 * just arrived from the socket into the OpenSSL buffer; done after an empty direct read, the socket is then no longer
 * readable and a descriptor-polling driver never invokes the watcher, so the read stalls. Checking first lets the
 * direct read drain whatever feof() pulled in. The race itself cannot be forced from userland; these tests pin the
 * observable behaviour over real TLS and plain sockets.
 */

it('reads tls records as they arrive and returns null once the peer closes', function () {
    [$client, $server] = TlsSocketPair::create();
    $stream = new ReadableResourceStream($client);

    $reader = async(function () use ($stream): array {
        $chunks = [];

        while (($chunk = $stream->read(new TimeoutCancellation(2))) !== null) {
            $chunks[] = $chunk;
        }

        return $chunks;
    });

    foreach (['one', 'two', 'three'] as $message) {
        delay(0.01);
        fwrite($server, $message);
    }

    delay(0.01);
    fclose($server);

    expect(implode('', $reader->await(new TimeoutCancellation(5))))->toBe('onetwothree')
        ->and($stream->isReadable())->toBeFalse()
        ->and($stream->isClosed())->toBeTrue();
});

it('returns data sent together with the tls close before reporting the end', function () {
    [$client, $server] = TlsSocketPair::create();
    fwrite($server, 'last words');
    fclose($server);
    usleep(20000);

    $stream = new ReadableResourceStream($client);

    expect($stream->read(new TimeoutCancellation(2)))->toBe('last words')
        ->and($stream->read(new TimeoutCancellation(2)))->toBeNull();
});

it('returns null without waiting when a plain socket peer already closed', function () {
    [$local, $remote] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($remote, 'data');
    fclose($remote);

    $stream = new ReadableResourceStream($local);

    expect($stream->read(new TimeoutCancellation(2)))->toBe('data')
        ->and($stream->read(new TimeoutCancellation(2)))->toBeNull()
        ->and($stream->isClosed())->toBeTrue();
});

it('waits for data on an idle plain socket', function () {
    [$local, $remote] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $stream = new ReadableResourceStream($local);

    $read = async(fn () => $stream->read(new TimeoutCancellation(2)));
    delay(0.02);
    fwrite($remote, 'late');

    expect($read->await())->toBe('late');

    $stream->close();
    fclose($remote);
});
