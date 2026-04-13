<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use function Fledge\Async\Http\Client\processRequest;

/**
 * @psalm-type RequestCallbackType = callable(Request, Cancellation, HttpStream):Response
 * @psalm-type ReleaseCallbackType = callable():void
 */
final class HttpStream implements Stream
{
    use ForbidSerialization;
    use ForbidCloning;

    /**
     * @param RequestCallbackType $RequestCallbackType
     * @param ReleaseCallbackType $ReleaseCallbackType
     */
    public static function fromConnection(
        Connection $connection,
        callable $RequestCallbackType,
        callable $ReleaseCallbackType
    ): self {
        return new self(
            $connection->getLocalAddress(),
            $connection->getRemoteAddress(),
            $connection->getTlsInfo(),
            $RequestCallbackType,
            $ReleaseCallbackType,
        );
    }

    /**
     * @param RequestCallbackType $RequestCallbackType
     * @param ReleaseCallbackType $ReleaseCallbackType
     */
    public static function fromStream(Stream $stream, callable $RequestCallbackType, callable $ReleaseCallbackType): self
    {
        return new self(
            $stream->getLocalAddress(),
            $stream->getRemoteAddress(),
            $stream->getTlsInfo(),
            $RequestCallbackType,
            $ReleaseCallbackType,
        );
    }

    /** @var callable */
    private $RequestCallbackType;

    /** @var callable|null */
    private $ReleaseCallbackType;

    /**
     * @param RequestCallbackType $RequestCallbackType
     * @param ReleaseCallbackType $ReleaseCallbackType
     */
    private function __construct(
        private readonly SocketAddress $localAddress,
        private readonly SocketAddress $remoteAddress,
        private readonly ?TlsInfo $tlsInfo,
        callable $RequestCallbackType,
        callable $ReleaseCallbackType,
    ) {
        $this->RequestCallbackType = $RequestCallbackType;
        $this->ReleaseCallbackType = $ReleaseCallbackType;
    }

    public function __destruct()
    {
        if ($this->ReleaseCallbackType !== null) {
            ($this->ReleaseCallbackType)();
        }
    }

    /**
     * @throws HttpException
     */
    public function request(Request $request, Cancellation $cancellation): Response
    {
        if ($this->ReleaseCallbackType === null) {
            throw new \Error('A stream may only be used for a single request');
        }

        $this->ReleaseCallbackType = null;

        return processRequest($request, [], fn (): Response => ($this->RequestCallbackType)($request, $cancellation, $this));
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->localAddress;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->remoteAddress;
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->tlsInfo;
    }
}
