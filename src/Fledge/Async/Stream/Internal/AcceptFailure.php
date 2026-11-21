<?php declare(strict_types=1);

namespace Fledge\Async\Stream\Internal;

use Psr\Log\LoggerInterface as PsrLogger;

/**
 * Classifies a failed stream_socket_accept() for {@see \Fledge\Async\Stream\ResourceServerSocket}.
 *
 * PHP reports an accept failure only as warning text ("Accept failed: <strerror>"); neither the warning nor the
 * PHP 8.6 stream error API ({@see \StreamError}, code AcceptFailed) carries the errno. The class is therefore read
 * from the strerror text, which PHP does not localise (it never sets LC_MESSAGES).
 *
 * Transient failures concern one connection (it went away between readiness and accept, or the readiness was
 * spurious) and are retried at once. Every other failure, resource exhaustion (EMFILE, ENFILE, ENOBUFS, ENOMEM)
 * and anything unrecognised, leaves the pending connection in the backlog, so the listener stays readable and an
 * immediate retry would spin: the caller backs off instead.
 *
 * @internal
 */
final class AcceptFailure
{
    /** Initial back-off in seconds, doubled after each failed retry. */
    public const float BACKOFF_MIN = 0.01;

    /** Upper bound of the back-off in seconds. */
    public const float BACKOFF_MAX = 1.0;

    /**
     * strerror() texts (glibc, musl, macOS/BSD) of errors that concern only the connection being accepted. Linux
     * passes pending network errors through accept() and documents them as "retry like EAGAIN".
     */
    private const array TRANSIENT = [
        'timed out', // ETIMEDOUT: PHP's own zero-timeout poll found nothing pending
        'temporarily unavailable', // EAGAIN
        'would block', // EWOULDBLOCK
        'interrupted', // EINTR
        'connection abort', // ECONNABORTED: "Software caused connection abort"
        'connection reset', // ECONNRESET
        'protocol error', // EPROTO
        'protocol not available', // ENOPROTOOPT
        'network is down', // ENETDOWN
        'network is unreachable', // ENETUNREACH
        'not on the network', // ENONET
        'host is down', // EHOSTDOWN
        'no route to host', // EHOSTUNREACH
    ];

    /**
     * True when retrying the accept at once is safe, false when the listener must back off.
     */
    public static function isTransient(?string $message): bool
    {
        if ($message === null) {
            return false;
        }

        $message = \strtolower($message);

        foreach (self::TRANSIENT as $needle) {
            if (\str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports an operational warning through the logger, or as E_USER_WARNING without one or when the logger fails
     * (it may need a descriptor the process does not have). An error handler that turns warnings into exceptions
     * must not break the accept loop, so such a handler is bypassed.
     */
    public static function warn(?PsrLogger $logger, string $message): void
    {
        if ($logger) {
            try {
                $logger->warning($message);
                return;
            } catch (\Throwable $exception) {
                $message .= '; the logger failed: ' . $exception->getMessage();
            }
        }

        try {
            \trigger_error($message, \E_USER_WARNING);
        } catch (\Throwable) {
            \set_error_handler(null);
            try {
                \trigger_error($message, \E_USER_WARNING);
            } finally {
                \restore_error_handler();
            }
        }
    }

    private function __construct()
    {
        // Static helper.
    }
}
