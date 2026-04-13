<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Client;

use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use Fledge\Async\WebSocket\Rfc6455Client;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Fledge\Async\WebSocket\WebsocketCloseInfo;
use Fledge\Async\WebSocket\WebsocketCount;
use Fledge\Async\WebSocket\WebsocketMessage;
use Fledge\Async\WebSocket\WebsocketTimestamp;
use Traversable;

/**
 * @implements  \IteratorAggregate<int, WebsocketMessage>
 */
final class Rfc6455Connection implements WebsocketConnection, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_MESSAGE_SIZE_LIMIT = (2 ** 20) * 10; // 10MB
    public const DEFAULT_FRAME_SIZE_LIMIT = (2 ** 20) * 10; // 10MB

    public function __construct(
        private readonly Rfc6455Client $client,
        private readonly Response $handshakeResponse,
    ) {
    }

    public function getHandshakeResponse(): Response
    {
        return $this->handshakeResponse;
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        return $this->client->receive($cancellation);
    }

    public function getId(): int
    {
        return $this->client->getId();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->client->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->client->getRemoteAddress();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->client->getTlsInfo();
    }

    public function getCloseInfo(): WebsocketCloseInfo
    {
        return $this->client->getCloseInfo();
    }

    public function sendText(string $data): void
    {
        $this->client->sendText($data);
    }

    public function sendBinary(string $data): void
    {
        $this->client->sendBinary($data);
    }

    public function streamText(ReadableStream $stream): void
    {
        $this->client->streamText($stream);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->client->streamBinary($stream);
    }

    public function ping(): void
    {
        $this->client->ping();
    }

    public function getCount(WebsocketCount $type): int
    {
        return $this->client->getCount($type);
    }

    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return $this->client->getTimestamp($type);
    }

    public function isClosed(): bool
    {
        return $this->client->isClosed();
    }

    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        $this->client->close($code, $reason);
    }

    public function onClose(\Closure $onClose): void
    {
        $this->client->onClose($onClose);
    }

    public function isCompressionEnabled(): bool
    {
        return $this->client->isCompressionEnabled();
    }

    public function getIterator(): Traversable
    {
        yield from $this->client;
    }
}
