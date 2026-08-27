<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\CancelledException;
use Fledge\Async\Dns\DnsException;
use Fledge\Async\Http\Client\SocketException as ClientSocketException;
use Fledge\Async\Http\Client\TimeoutException as ClientTimeoutException;
use Fledge\Async\Stream\SocketException as StreamSocketException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Maps Fledge Async transport exceptions onto the Guzzle exception hierarchy.
 *
 * Guzzle middleware and Illuminate's HTTP client both dispatch on Guzzle
 * exception types: PendingRequest catches TransferException to raise
 * Illuminate\Http\Client\ConnectionException and fire the ConnectionFailed
 * event. Rejecting with raw async exceptions breaks that contract, so the
 * handler maps every failure through here before rejecting the promise.
 */
class GuzzleExceptionMapper
{
    /**
     * Map a transport exception to its Guzzle equivalent.
     *
     * Guzzle exceptions pass through unchanged. Connection-level failures
     * (socket, TLS, DNS, connect timeouts, cancellations) become
     * ConnectException so callers see the same type curl would produce.
     * Everything else becomes a RequestException.
     */
    public static function map(\Throwable $e, RequestInterface $request, ?ResponseInterface $response = null): \Throwable
    {
        if ($e instanceof GuzzleException) {
            return $e;
        }

        if (static::isConnectFailure($e)) {
            return new ConnectException(static::message($e), $request, $e);
        }

        return static::toRequestException(static::message($e), $request, $response, $e);
    }

    /**
     * Whether the exception represents a failure to establish the connection.
     *
     * Notes on the hierarchy: the client TlsException extends the client
     * SocketException, and the stream ConnectException extends the stream
     * SocketException, so both are covered by their parents below. The client
     * TimeoutException also fires for transfer timeouts, which matches curl:
     * CURLE_OPERATION_TIMEDOUT is treated as a connection error by Guzzle.
     */
    protected static function isConnectFailure(\Throwable $e): bool
    {
        return $e instanceof ClientSocketException
            || $e instanceof ClientTimeoutException
            || $e instanceof StreamSocketException
            || $e instanceof DnsException
            || $e instanceof CancelledException;
    }

    protected static function message(\Throwable $e): string
    {
        return $e->getMessage() !== '' ? $e->getMessage() : 'Transfer failed: '.$e::class;
    }

    /**
     * Build a RequestException across Guzzle major versions.
     *
     * Guzzle 7 accepts the response as the third constructor argument, while
     * Guzzle 8 moved response-carrying failures to ResponseException.
     */
    protected static function toRequestException(
        string $message,
        RequestInterface $request,
        ?ResponseInterface $response,
        \Throwable $previous,
    ): RequestException {
        static $legacySignature = null;

        $legacySignature ??= (new \ReflectionClass(RequestException::class))
            ->getConstructor()
            ?->getParameters()[2]
            ?->getName() === 'response';

        if ($legacySignature) {
            return new RequestException($message, $request, $response, $previous);
        }

        if ($response !== null) {
            /** @phpstan-ignore-next-line ResponseException only exists on Guzzle 8 */
            return new \GuzzleHttp\Exception\ResponseException($message, $request, $response, $previous);
        }

        return new RequestException($message, $request, 0, $previous);
    }
}
