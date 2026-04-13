<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Ipc;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Stream;
use Fledge\Async\Stream\ResourceSocket;
use Revolt\EventLoop;
use const Fledge\Async\Process\IS_WINDOWS;

final class LocalIpcHub implements IpcHub
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly SocketIpcHub $delegate;

    private ?string $toUnlink = null;

    /**
     * @param float $keyReceiveTimeout Timeout to receive the key on accepted connections.
     * @param positive-int $keyLength Length of the random key exchanged on the IPC channel when connecting.
     *
     * @throws Stream\SocketException
     */
    public function __construct(
        float $keyReceiveTimeout = SocketIpcHub::DEFAULT_KEY_RECEIVE_TIMEOUT,
        int $keyLength = SocketIpcHub::DEFAULT_KEY_LENGTH,
    ) {
        if (IS_WINDOWS) {
            $address = new Stream\InternetAddress('127.0.0.1', 0);
        } else {
            $suffix = \bin2hex(\random_bytes(10));
            $path = \sys_get_temp_dir() . "/fledge-parallel-ipc-" . $suffix . ".sock";
            $address = new Stream\UnixAddress($path);
            $this->toUnlink = $path;
        }

        $this->delegate = new SocketIpcHub(Stream\listen($address), $keyReceiveTimeout, $keyLength);
    }

    public function __destruct()
    {
        EventLoop::queue($this->delegate->close(...));
        $this->unlink();
    }

    public function accept(string $key, ?Cancellation $cancellation = null): ResourceSocket
    {
        return $this->delegate->accept($key, $cancellation);
    }

    public function isClosed(): bool
    {
        return $this->delegate->isClosed();
    }

    public function close(): void
    {
        $this->delegate->close();
        $this->unlink();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->delegate->onClose($onClose);
    }

    private function unlink(): void
    {
        if ($this->toUnlink === null) {
            return;
        }

        // Ignore errors when unlinking temp socket.
        \set_error_handler(static fn () => true);
        try {
            \unlink($this->toUnlink);
        } finally {
            \restore_error_handler();
            $this->toUnlink = null;
        }
    }

    public function getUri(): string
    {
        return $this->delegate->getUri();
    }

    public function generateKey(): string
    {
        return $this->delegate->generateKey();
    }
}
