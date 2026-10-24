<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\WritableStream;

interface HttpDriver
{
    public const DEFAULT_CONNECTION_TIMEOUT = 60;
    public const DEFAULT_STREAM_TIMEOUT = 15;
    public const DEFAULT_HEADER_SIZE_LIMIT = 32768;
    public const DEFAULT_BODY_SIZE_LIMIT = 131072;

    /** HTTP/2: low-value frames (PRIORITY, unknown, empty DATA/CONTINUATION) allowed per window. */
    public const DEFAULT_LOW_VALUE_FRAME_LIMIT = 5000;

    /** HTTP/2: seconds per low-value frame counting window. */
    public const DEFAULT_LOW_VALUE_FRAME_WINDOW = 10;

    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;

    /**
     * Set up the driver.
     */
    public function handleClient(
        Client $client,
        ReadableStream $readableStream,
        WritableStream $writableStream,
    ): void;

    /**
     * @return int Number of requests that are being read by the parser.
     */
    public function getPendingRequestCount(): int;

    /**
     * Stops processing further requests, returning once all currently pending requests have been fulfilled and any
     * remaining data is sent to the client (such as GOAWAY frames for HTTP/2).
     */
    public function stop(): void;
}
