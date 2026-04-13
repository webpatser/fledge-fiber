<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Ipc;

use Fledge\Async\Stream\ReadableResourceStream;
use Fledge\Async\Cancellation;
use Fledge\Async\Stream\Socket;
use Fledge\Async\Stream\SocketConnector;
use function Fledge\Async\Stream\socketConnector;

/**
 * @param positive-int $keyLength
 */
function readKey(
    ReadableResourceStream|Socket $stream,
    ?Cancellation $cancellation = null,
    int $keyLength = SocketIpcHub::DEFAULT_KEY_LENGTH,
): string {
    $key = "";

    // Read random key from $stream and send back to parent over IPC socket to authenticate.
    do {
        /** @psalm-suppress InvalidArgument */
        if (($chunk = $stream->read($cancellation, $keyLength - \strlen($key))) === null) {
            throw new \RuntimeException("Could not read key from parent", E_USER_ERROR);
        }
        $key .= $chunk;
    } while (\strlen($key) < $keyLength);

    return $key;
}

/**
 * Note that this is designed to be used in the child process/thread to connect to an IPC socket.
 */
function connect(
    string $uri,
    string $key,
    ?Cancellation $cancellation = null,
    ?SocketConnector $connector = null,
): Socket {
    $connector ??= socketConnector();

    $client = $connector->connect($uri, cancellation: $cancellation);
    $client->write($key);

    return $client;
}
