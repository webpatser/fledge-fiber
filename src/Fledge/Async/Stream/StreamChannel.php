<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use Fledge\Async\Stream\Internal\ChannelParser;
use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Serialization\Serializer;
use Fledge\Async\Sync\Channel;
use Fledge\Async\Sync\ChannelException;
use Fledge\Async\Sync\LocalMutex;
use Fledge\Async\Sync\Mutex;
use function Fledge\Async\async;

/**
 * An asynchronous channel for sending data between threads and processes.
 *
 * Supports full duplex read and write.
 *
 * @template TReceive
 * @template TSend
 * @template-implements Channel<TReceive, TSend>
 */
final readonly class StreamChannel implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    private ChannelParser $parser;

    /** @var \SplQueue<TReceive> */
    private \SplQueue $received;

    private Mutex $readMutex;

    /**
     * Creates a new channel from the given stream objects. Note that $read and $write can be the same object.
     */
    public function __construct(
        private ReadableStream $read,
        private WritableStream $write,
        ?Serializer $serializer = null,
    ) {
        $this->received = new \SplQueue();
        $this->readMutex = new LocalMutex();
        $this->parser = new ChannelParser($this->received->push(...), $serializer);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Closes the read and write resource streams.
     */
    public function close(): void
    {
        $this->read->close();
        $this->write->close();
    }

    public function send(mixed $data): void
    {
        $data = $this->parser->encode($data);

        try {
            $this->write->write($data);
        } catch (\Throwable $exception) {
            throw new ChannelException("Sending on the channel failed. Did the context die?", 0, $exception);
        }
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        $cancellation?->throwIfRequested();

        $lock = $this->readMutex->acquire();

        try {
            while ($this->received->isEmpty()) {
                try {
                    $chunk = $this->read->read($cancellation);
                } catch (StreamException $exception) {
                    throw new ChannelException(
                        "Reading from the channel failed. Did the context die?",
                        0,
                        $exception,
                    );
                }

                if ($chunk === null) {
                    throw new ChannelException("The channel closed while waiting to receive the next value");
                }

                $this->parser->push($chunk);
            }

            return $this->received->shift();
        } finally {
            async($lock->release(...));
        }
    }

    public function isClosed(): bool
    {
        return $this->read->isClosed() || $this->write->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->read->onClose($onClose);
    }
}
