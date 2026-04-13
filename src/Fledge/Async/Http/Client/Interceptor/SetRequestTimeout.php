<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\Request;

final class SetRequestTimeout extends ModifyRequest
{
    public function __construct(
        float $tcpConnectTimeout = 10,
        float $tlsHandshakeTimeout = 10,
        float $transferTimeout = 10,
        ?float $inactivityTimeout = null,
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
