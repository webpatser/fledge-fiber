<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use Fledge\Async\Stream\ResourceStream;
use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Stream\Internal\AcceptFailure;
use Fledge\Loop;
use Fledge\Loop\Suspension;
use Psr\Log\LoggerInterface as PsrLogger;

final class ResourceServerSocket implements ServerSocket, ResourceStream
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var resource|null Stream socket server resource. */
    private $socket;

    private readonly string $callbackId;

    private readonly SocketAddress $address;

    private ?Suspension $acceptor = null;

    /** @var \Closure(CancelledException):void */
    private readonly \Closure $cancel;

    private readonly \Closure $errorHandler;

    private readonly DeferredFuture $onClose;

    /** Last warning text of stream_socket_accept(), captured by the error handler. */
    private ?string $acceptError = null;

    /** Timer that resumes accepting after a failed accept, null while not backing off. */
    private ?string $backoffTimerId = null;

    /** Current back-off delay in seconds, 0 while accepting normally. */
    private float $backoffDelay = 0.0;

    private bool $referenced = true;

    /**
     * @param resource $socket A bound socket server resource
     * @param positive-int $chunkSize Chunk size for the input and output stream.
     * @param PsrLogger|null $logger Receives a warning when accepting pauses on a persistent accept failure (such
     *     as the open file limit) and when it resumes. Without a logger these are raised as E_USER_WARNING.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct(
        $socket,
        private readonly BindContext $bindContext,
        private readonly int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE,
        private readonly ?PsrLogger $logger = null,
    ) {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->address = SocketAddress\fromResourceLocal($socket);

        // Ignore any errors raised while this handler is set. Errors will be checked through return values; the
        // message is kept to tell a transient accept failure from one that needs a back-off.
        $acceptError = &$this->acceptError;
        $this->errorHandler = static function (int $errno, string $message) use (&$acceptError): bool {
            $acceptError = $message;
            return true;
        };

        $this->onClose = new DeferredFuture;

        // Load the accept failure helper now: when it is needed the process may be out of descriptors to autoload it.
        \class_exists(AcceptFailure::class);

        \stream_set_blocking($this->socket, false);

        $acceptor = &$this->acceptor;
        $this->callbackId = Loop::onReadable($this->socket, static function () use (&$acceptor): void {
            $acceptor?->resume(true);
            $acceptor = null;
        });

        $callbackId = $this->callbackId;
        $this->cancel = static function (CancelledException $exception) use (&$acceptor, $callbackId): void {
            Loop::disable($callbackId);

            $acceptor?->throw($exception);
            $acceptor = null;
        };

        Loop::disable($this->callbackId);
    }

    /**
     * Automatically cancels the loop watcher.
     */
    public function __destruct()
    {
        if (!$this->socket) {
            return;
        }

        $this->free();
    }

    private function free(): void
    {
        Loop::cancel($this->callbackId);
        $this->cancelBackoff();

        $this->socket = null;

        $this->acceptor?->resume(false);
        $this->acceptor = null;

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?ResourceSocket
    {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->socket) {
            return null; // Resolve with null when server is closed.
        }

        if ($this->backoffTimerId === null && $client = $this->acceptSocketClient()) {
            return ResourceSocket::fromServerSocket($client, $this->chunkSize);
        }

        $id = $cancellation?->subscribe($this->cancel);

        try {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            do {
                // While backing off the readable callback stays disabled and the back-off timer resumes instead.
                if ($this->backoffTimerId === null) {
                    Loop::enable($this->callbackId);
                }

                $this->acceptor = Loop::getSuspension();
                if (!$this->acceptor->suspend()) {
                    return null;
                }
            } while (!$client = $this->acceptSocketClient());

            /** @var resource $client Psalm 5.x seems to think $client is of type 'never' */
            return ResourceSocket::fromServerSocket($client, $this->chunkSize);
        } finally {
            Loop::disable($this->callbackId);

            /** @psalm-suppress PossiblyNullArgument $id is always defined if $cancellation is non-null */
            $cancellation?->unsubscribe($id);
        }
    }

    /**
     * @return resource|false
     */
    private function acceptSocketClient()
    {
        \assert($this->socket !== null, "Unexpected server state");

        $this->acceptError = null;

        \set_error_handler($this->errorHandler);

        try {
            $client = \stream_socket_accept($this->socket, 0); // Timeout of 0 to be non-blocking.
        } finally {
            \restore_error_handler();
        }

        if ($client) {
            if ($this->backoffDelay > 0.0) {
                $this->backoffDelay = 0.0;
                AcceptFailure::warn($this->logger, \sprintf(
                    'Accepting connections on %s resumed',
                    $this->address->toString(),
                ));
            }

            return $client;
        }

        if (!AcceptFailure::isTransient($this->acceptError)) {
            $this->backOff();
        }

        return false;
    }

    /**
     * The failed accept left the pending connection in the backlog (open file limit and other resource
     * exhaustion), so the listener stays readable: pause the readable callback and retry from a timer.
     */
    private function backOff(): void
    {
        Loop::disable($this->callbackId);
        $this->cancelBackoff();

        if ($this->backoffDelay === 0.0) {
            $this->backoffDelay = AcceptFailure::BACKOFF_MIN;
            AcceptFailure::warn($this->logger, \sprintf(
                'Accepting connections on %s failed (%s); pausing accept on this listener and retrying with a '
                . 'back-off of up to %d ms until it succeeds',
                $this->address->toString(),
                $this->acceptError ?? 'unknown error',
                (int) (AcceptFailure::BACKOFF_MAX * 1000),
            ));
        } else {
            $this->backoffDelay = \min($this->backoffDelay * 2, AcceptFailure::BACKOFF_MAX);
        }

        $acceptor = &$this->acceptor;
        $backoffTimerId = &$this->backoffTimerId;
        $this->backoffTimerId = Loop::delay(
            $this->backoffDelay,
            static function () use (&$acceptor, &$backoffTimerId): void {
                $backoffTimerId = null;
                $acceptor?->resume(true);
                $acceptor = null;
            },
        );

        if (!$this->referenced) {
            Loop::unreference($this->backoffTimerId);
        }
    }

    private function cancelBackoff(): void
    {
        if ($this->backoffTimerId !== null) {
            Loop::cancel($this->backoffTimerId);
            $this->backoffTimerId = null;
        }
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close(): void
    {
        // Cancel the loop callbacks before the resource is closed: the loop must never watch a closed stream.
        Loop::cancel($this->callbackId);
        $this->cancelBackoff();

        if ($this->socket) {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            \fclose($this->socket);
        }

        $this->free();
    }

    public function isClosed(): bool
    {
        return $this->socket === null;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose)->ignore();
    }

    /**
     * References the readability callback used for detecting new connection attempts in {@code accept()}.
     *
     * @see Loop::reference()
     */
    public function reference(): void
    {
        if ($this->socket === null) {
            return;
        }

        $this->referenced = true;
        Loop::reference($this->callbackId);

        if ($this->backoffTimerId !== null) {
            Loop::reference($this->backoffTimerId);
        }
    }

    /**
     * Unreferences the readability callback used for detecting new connection attempts in {@code accept()}.
     *
     * @see Loop::unreference()
     */
    public function unreference(): void
    {
        if ($this->socket === null) {
            return;
        }

        $this->referenced = false;
        Loop::unreference($this->callbackId);

        if ($this->backoffTimerId !== null) {
            Loop::unreference($this->backoffTimerId);
        }
    }

    public function getAddress(): SocketAddress
    {
        return $this->address;
    }

    public function getBindContext(): BindContext
    {
        return $this->bindContext;
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource()
    {
        return $this->socket;
    }
}
