<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Request;
use Time\Duration;

final class SetRequestTimeout extends ModifyRequest
{
    /**
     * @param Duration|float $tcpConnectTimeout Timeout for the TCP connection, as a `Duration` or in seconds. Use 0 for
     *     no timeout.
     * @param Duration|float $tlsHandshakeTimeout Timeout for the TLS handshake, as a `Duration` or in seconds. Use 0
     *     for no timeout.
     * @param Duration|float $transferTimeout Timeout for the HTTP transfer, as a `Duration` or in seconds. Use 0 for no
     *     timeout.
     * @param Duration|float|null $inactivityTimeout Timeout since the last data was received, as a `Duration` or in
     *     seconds. Null keeps the request's own value.
     */
    public function __construct(
        Duration|float $tcpConnectTimeout = 10,
        Duration|float $tlsHandshakeTimeout = 10,
        Duration|float $transferTimeout = 10,
        Duration|float|null $inactivityTimeout = null,
    ) {
        parent::__construct(static function (Request $request) use (
            $tcpConnectTimeout,
            $tlsHandshakeTimeout,
            $transferTimeout,
            $inactivityTimeout
        ) {
            $request->setTcpConnectTimeout($tcpConnectTimeout);
            $request->setTlsHandshakeTimeout($tlsHandshakeTimeout);
            $request->setTransferTimeout($transferTimeout);

            if (null !== $inactivityTimeout) {
                $request->setInactivityTimeout($inactivityTimeout);
            }

            return $request;
        });
    }
}
