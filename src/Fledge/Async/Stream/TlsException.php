<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

/**
 * Thrown if TLS can't be properly negotiated or is not supported on the given socket.
 */
class TlsException extends SocketException
{
}
