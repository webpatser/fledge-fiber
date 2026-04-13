<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\RequestHandler;
use Psr\Log\LoggerInterface as PsrLogger;

final readonly class DefaultHttpDriverFactory implements HttpDriverFactory
{
    /**
     * @param bool $allowHttp2Upgrade Requires HTTP/2 support to be enabled.
     * @param bool $pushEnabled Requires HTTP/2 support to be enabled.
     */
    public function __construct(
        private PsrLogger $logger,
        private int $streamTimeout = HttpDriver::DEFAULT_STREAM_TIMEOUT,
        private int $connectionTimeout = HttpDriver::DEFAULT_CONNECTION_TIMEOUT,
        private int $headerSizeLimit = HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
        private int $bodySizeLimit = HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
        private bool $http2Enabled = true,
        private bool $allowHttp2Upgrade = false,
        private bool $pushEnabled = true,
    ) {
    }

    public function createHttpDriver(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Client $client,
    ): HttpDriver {
        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver(
                requestHandler: $requestHandler,
                errorHandler: $errorHandler,
                logger: $this->logger,
                streamTimeout: $this->streamTimeout,
                connectionTimeout: $this->connectionTimeout,
                headerSizeLimit: $this->headerSizeLimit,
                bodySizeLimit: $this->bodySizeLimit,
                pushEnabled: $this->pushEnabled,
            );
        }

        return new Http1Driver(
            requestHandler: $requestHandler,
            errorHandler: $errorHandler,
            logger: $this->logger,
            connectionTimeout: $this->streamTimeout, // Intentional use of stream instead of connection timeout
            headerSizeLimit: $this->headerSizeLimit,
            bodySizeLimit: $this->bodySizeLimit,
            allowHttp2Upgrade: $this->http2Enabled && $this->allowHttp2Upgrade,
        );
    }

    public function getApplicationLayerProtocols(): array
    {
        if ($this->http2Enabled) {
            return ["h2", "http/1.1"];
        }

        return ["http/1.1"];
    }
}
