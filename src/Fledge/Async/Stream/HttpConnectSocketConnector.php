<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * Connects through an HTTP proxy by establishing a CONNECT tunnel.
 *
 * The connector dials the proxy, issues a CONNECT request for the target
 * authority, and hands back the raw socket once the proxy replies with a
 * 2xx status. TLS to the origin is not part of the tunnel setup; it happens
 * later on the returned socket (for HTTPS targets the HTTP client's
 * connection factory performs the handshake), so this connector composes
 * with TLS contexts exactly like a direct connection.
 *
 * @api
 */
final readonly class HttpConnectSocketConnector implements SocketConnector
{
    private const MAX_RESPONSE_HEADER_SIZE = 8192;

    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private SocketAddress|string $proxyAddress,
        private ?string $username = null,
        private ?string $password = null,
        private ?SocketConnector $socketConnector = null,
    ) {
        if (($username === null) !== ($password === null)) {
            throw new \Error('Both or neither username and password must be provided!');
        }
    }

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $connector = $this->socketConnector ?? socketConnector();

        $socket = $connector->connect($this->proxyAddress, $context, $cancellation);

        try {
            self::tunnel($socket, (string) $uri, $this->username, $this->password, $cancellation);
        } catch (\Throwable $e) {
            $socket->close();

            throw $e;
        }

        return $socket;
    }

    /**
     * Establish a CONNECT tunnel to the target authority over the given socket.
     *
     * @throws SocketException
     * @throws StreamException
     * @see https://datatracker.ietf.org/doc/html/rfc9110#section-9.3.6
     */
    public static function tunnel(
        Socket $socket,
        string $target,
        ?string $username,
        ?string $password,
        ?Cancellation $cancellation,
    ): void {
        if (($username === null) !== ($password === null)) {
            throw new \Error('Both or neither username and password must be provided!');
        }

        $authority = self::authority($target);

        $request = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\n";

        if ($username !== null && $password !== null) {
            $request .= 'Proxy-Authorization: Basic '.\base64_encode("{$username}:{$password}")."\r\n";
        }

        $request .= "\r\n";

        $socket->write($request);

        // The proxy sends nothing after its response until origin data flows,
        // and the client speaks first on every protocol tunnelled here (TLS
        // handshakes and plain HTTP requests alike), so reading up to the
        // header terminator cannot swallow origin bytes.
        $buffer = '';

        while (!\str_contains($buffer, "\r\n\r\n")) {
            if (\strlen($buffer) > self::MAX_RESPONSE_HEADER_SIZE) {
                throw new SocketException('Proxy CONNECT response exceeded '.self::MAX_RESPONSE_HEADER_SIZE.' bytes');
            }

            $chunk = $socket->read($cancellation);

            if ($chunk === null) {
                throw new SocketException('The socket was closed before the tunnel could be established');
            }

            $buffer .= $chunk;
        }

        if (!\preg_match('(^HTTP/1\.[01] (\d{3})[^\r\n]*\r\n)', $buffer, $match)) {
            throw new SocketException('Invalid CONNECT response from proxy: '.\strtok($buffer, "\r\n"));
        }

        $status = (int) $match[1];

        if ($status < 200 || $status > 299) {
            throw new SocketException("Proxy refused the CONNECT tunnel to '{$authority}' with status {$status}");
        }
    }

    /**
     * Extract the host:port authority from a connect target such as "tcp://host:port".
     *
     * @throws SocketException
     */
    private static function authority(string $target): string
    {
        $position = \strpos($target, '://');
        $authority = $position === false ? $target : \substr($target, $position + 3);

        if ($authority === '' || !\str_contains($authority, ':')) {
            throw new SocketException("Invalid tunnel target '{$target}', expected host:port");
        }

        return $authority;
    }
}
