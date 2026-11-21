<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Server\Internal;

use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Future;
use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Fledge\Async\WebSocket\WebsocketClosedException;
use Fledge\Loop;
use Fledge\Loop\Suspension;

/** @internal */
final class SendQueue
{
    use ForbidCloning;
    use ForbidSerialization;

    private const OVERFLOW_REASON = 'Send queue limit exceeded';

    /** @var \SplQueue<array{DeferredFuture, string, bool}> */
    private \SplQueue $writeQueue;

    /** @var Suspension<bool>|null */
    private ?Suspension $suspension = null;

    /** Bytes of message data waiting in the queue, not counting the message being written. */
    private int $queuedBytes = 0;

    /** True while the drain fiber is writing a message to the client. */
    private bool $inFlight = false;

    private ?WebsocketClosedException $overflow = null;

    /**
     * @param int|null $maxQueuedBytes Close the client with POLICY_VIOLATION once queued message bytes would pass
     * this limit. Use null for no limit.
     * @param int|null $maxQueuedMessages Close the client with POLICY_VIOLATION once the number of queued messages
     * would pass this limit. Use null for no limit.
     */
    public function __construct(
        private readonly WebsocketClient $client,
        private readonly ?int $maxQueuedBytes = null,
        private readonly ?int $maxQueuedMessages = null,
    ) {
        $this->writeQueue = $writeQueue = new \SplQueue;

        $suspension = &$this->suspension;
        $queuedBytes = &$this->queuedBytes;
        $inFlight = &$this->inFlight;
        Loop::queue(static function () use ($writeQueue, $client, &$suspension, &$queuedBytes, &$inFlight): void {
            while (!$client->isClosed()) {
                if ($writeQueue->isEmpty()) {
                    $suspension = Loop::getSuspension();
                    if (!$suspension->suspend()) {
                        break;
                    }
                }

                self::dequeue($writeQueue, $client, $queuedBytes, $inFlight);
            }

            // Sends still queued once the client closed would otherwise never resolve.
            self::failQueued($writeQueue, self::createClosedException($client));
            $queuedBytes = 0;
        });
    }

    private static function dequeue(
        \SplQueue $writeQueue,
        WebsocketClient $client,
        int &$queuedBytes,
        bool &$inFlight,
    ): void {
        while (!$writeQueue->isEmpty() && !$client->isClosed()) {
            /**
             * @var DeferredFuture $deferredFuture
             * @var string $data
             * @var bool $binary
             */
            [$deferredFuture, $data, $binary] = $writeQueue->dequeue();
            $queuedBytes -= \strlen($data);
            $inFlight = true;

            try {
                $binary ? $client->sendBinary($data) : $client->sendText($data);
                $deferredFuture->complete();
            } catch (\Throwable $exception) {
                // Awaiting still throws; a caller that dropped the future gets no unhandled error in the event loop.
                $deferredFuture->getFuture()->ignore();
                $deferredFuture->error($exception);
                self::failQueued($writeQueue, match (true) {
                    $exception instanceof WebsocketClosedException => $exception,
                    $client->isClosed() => self::createClosedException($client),
                    default => new WebsocketClosedException(
                        'Writing to the client failed',
                        WebsocketCloseCode::ABNORMAL_CLOSE,
                        '',
                        $exception,
                    ),
                });
                $queuedBytes = 0;
                return;
            } finally {
                $inFlight = false;
            }
        }
    }

    private static function failQueued(\SplQueue $writeQueue, WebsocketClosedException $exception): void
    {
        while (!$writeQueue->isEmpty()) {
            /** @var DeferredFuture $deferredFuture */
            [$deferredFuture] = $writeQueue->dequeue();
            // Awaiting still throws; a caller that dropped the future gets no unhandled error in the event loop.
            $deferredFuture->getFuture()->ignore();
            $deferredFuture->error($exception);
        }
    }

    private static function createClosedException(WebsocketClient $client): WebsocketClosedException
    {
        if (!$client->isClosed()) {
            return new WebsocketClosedException(
                'Send queue disposed before the message was sent',
                WebsocketCloseCode::GOING_AWAY,
                '',
            );
        }

        $closeInfo = $client->getCloseInfo();

        return new WebsocketClosedException(
            'Client closed before the message was sent',
            $closeInfo->getCode(),
            $closeInfo->getReason(),
        );
    }

    public function __destruct()
    {
        $this->suspension?->resume(false);
        $this->suspension = null;
    }

    /**
     * @return Future<void>
     */
    public function send(string $data, bool $binary): Future
    {
        if ($this->overflow) {
            return Future::error($this->overflow)->ignore();
        }

        if ($this->client->isClosed()) {
            return Future::error(self::createClosedException($this->client))->ignore();
        }

        // The limits bound what piles up behind a slow reader, not the size of one message: with nothing queued and
        // nothing in flight a message is always admitted, whatever its size.
        $length = \strlen($data);
        $idle = !$this->inFlight && $this->writeQueue->isEmpty();
        if (!$idle && (
            ($this->maxQueuedMessages !== null && $this->writeQueue->count() >= $this->maxQueuedMessages)
            || ($this->maxQueuedBytes !== null && $this->queuedBytes + $length > $this->maxQueuedBytes)
        )) {
            return $this->overflow();
        }

        $deferredFuture = new DeferredFuture();
        $this->writeQueue->enqueue([$deferredFuture, $data, $binary]);
        $this->queuedBytes += $length;
        $this->suspension?->resume(true);
        $this->suspension = null;

        return $deferredFuture->getFuture();
    }

    /**
     * The peer is not reading fast enough: fail everything queued and close the client.
     *
     * @return Future<never>
     */
    private function overflow(): Future
    {
        $this->overflow = $exception = new WebsocketClosedException(
            'Client is not reading fast enough',
            WebsocketCloseCode::POLICY_VIOLATION,
            self::OVERFLOW_REASON,
        );

        self::failQueued($this->writeQueue, $exception);
        $this->queuedBytes = 0;

        // close() waits for the closing handshake, so it must not block the sending fiber.
        Loop::queue($this->client->close(...), WebsocketCloseCode::POLICY_VIOLATION, self::OVERFLOW_REASON);

        return Future::error($exception)->ignore();
    }
}
