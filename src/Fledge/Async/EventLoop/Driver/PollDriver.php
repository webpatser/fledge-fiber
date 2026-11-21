<?php

declare(strict_types=1);

namespace Fledge\Async\EventLoop\Driver;

use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Io\Poll\Watcher;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\UnsupportedFeatureException;
use Time\Duration;

/**
 * Revolt event loop driver on PHP 8.6's Io\Poll (kqueue on macOS and BSD, epoll on Linux, poll(2) anywhere).
 *
 * Unlike StreamSelectDriver it has no FD_SETSIZE limit, and its wait cost does not grow with the number of idle
 * descriptors on the kqueue and epoll backends. Behaviour (callback order, timers, signals) follows Revolt's
 * StreamSelectDriver.
 *
 * Streams: one Io\Poll\Watcher per stream carries the merged read/write interest of all enabled callbacks for that
 * stream, because a Context refuses the same descriptor twice. Interest changes are collected and applied once per
 * tick, right before the wait: the watcher is modified when the interest changes, removed when it becomes empty (an
 * empty event list is rejected by the API) and added again when it returns.
 *
 * Fallback set: some streams cannot be added to a backend (character devices such as /dev/null on kqueue and on
 * macOS poll(2), which accepts them but never reports them ready; regular files on epoll; a second resource for an
 * already watched descriptor). Those streams are kept in a fallback set that is checked every tick with a
 * zero-timeout stream_select(). While the fallback set is non-empty next to backend watchers, the backend wait is
 * capped at FALLBACK_INTERVAL seconds, so a fallback stream is noticed at most that late. The trade-off is up to
 * 100 extra wakeups per second while such a stream is watched (a few microseconds of CPU each) in exchange for not
 * running select() over every descriptor. When only fallback streams exist, stream_select() itself is the blocking
 * call and there is no added latency.
 *
 * Streams neither the backend nor stream_select() can represent (php://memory) also land in the fallback set, so
 * they fail exactly as in StreamSelectDriver: registering succeeds and the next dispatch throws the same exception
 * out of run().
 *
 * Closed streams: a stream closed while callbacks are still registered is dropped by the backend without an event.
 * Before every blocking wait the driver checks all its watchers for this, which costs O(watched streams) per
 * blocking tick. A stream found closed is treated as ready for read and write, so its callbacks run (a reader sees
 * an invalid stream) until they are cancelled. This deliberately differs from StreamSelectDriver, which throws a
 * TypeError or ValueError out of run() for a closed watched stream. Hangup and error events from the backend wake
 * both the read and the write callbacks of the stream.
 *
 * Signals: pcntl_signal() and pcntl_signal_dispatch(), as in StreamSelectDriver. A signal arriving during the
 * backend wait interrupts it; the interrupted wait counts as zero events and the signal is dispatched next tick.
 *
 * Fork: the driver belongs to the process that created it. A forked child inherits the epoll descriptor on Linux
 * (shared with the parent: registering, modifying or removing a watcher in the child changes the parent's
 * registrations, and the parent then receives events whose data refers to the child's memory) and loses the kqueue
 * descriptor on macOS and BSD (its number becomes free and is reused by the child's next descriptor). The driver
 * therefore records getmypid() at construction and refuses to touch the kernel object from any other process: the
 * sync of watcher changes and every wait on the Context throw a LogicException naming
 * {@see \Fledge\Async\EventLoop\LoopDriver::afterFork()}, which is what a forked child calls first to discard the
 * inherited driver and start a fresh loop. The check is one getmypid() before each wait syscall (about 3 ns on
 * macOS, about 125 ns in a Linux container, where getpid() is a real syscall); ticks without watchers, which sleep
 * or select instead, do not pay it.
 * Destroying the driver in a process that did not create it issues no kernel operation and leaves the signal
 * dispositions alone.
 *
 * LoopDriver::afterFork() is the only full protection. Without it, a shutdown function releases the Context in a
 * child before PHP closes the remaining streams, so a plain child exit leaves the parent's registrations intact; that
 * hook is best effort: a shutdown function registered before this driver was created runs first, and if it closes a
 * stream the parent watches, the still-alive inherited Context deletes the parent's registration. Fibers suspended in
 * the parent unwind in the child either way; fork from a quiet point.
 */
final class PollDriver extends AbstractDriver
{
    /** Upper bound in seconds for a backend wait while select-checked fallback streams are watched. */
    private const float FALLBACK_INTERVAL = 0.01;

    private const int READ = 1;

    private const int WRITE = 2;

    /** Null once {@see self::releaseAfterFork()} has dropped it. */
    private ?Context $context = null;

    /** Process that created the driver and owns the kernel object behind the Context. */
    private readonly int $pid;

    /**
     * Contexts of drivers destroyed in a forked child without {@see self::releaseAfterFork()}. On kqueue the number
     * of the (not inherited) descriptor may already belong to another descriptor of the child, so these are never
     * closed before the process exits.
     *
     * @var list<Context>
     */
    private static array $orphanedContexts = [];

    /** @var array<int, resource> */
    private array $streams = [];

    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $readCallbacks = [];

    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $writeCallbacks = [];

    /** @var array<int, Watcher> */
    private array $watchers = [];

    /** @var array<int, int> Interest bitmask currently registered with each watcher. */
    private array $watcherInterest = [];

    /** @var array<int, true> Streams whose interest changed since the last sync. */
    private array $dirty = [];

    /** @var array<int, resource> Rejected by the backend, checked with stream_select() every tick. */
    private array $selectStreams = [];

    /** @var array<int, true> Closed while watched: treated as ready until their callbacks are cancelled. */
    private array $readyStreams = [];

    private readonly TimerQueue $timerQueue;

    /** @var array<int, array<string, SignalCallback>> */
    private array $signalCallbacks = [];

    /** @var \SplQueue<int> */
    private readonly \SplQueue $signalQueue;

    private readonly bool $signalHandling;

    private readonly Duration $zero;

    /**
     * macOS poll(2) accepts character devices such as /dev/null but never reports them ready, so on the Poll backend
     * there they go to the fallback set instead of the backend.
     */
    private readonly bool $rejectCharacterDevices;

    private readonly \Closure $streamSelectErrorHandler;

    private bool $streamSelectIgnoreResult = false;

    public function __construct(Backend $backend = Backend::Auto)
    {
        parent::__construct();

        $this->pid = \getmypid();
        $this->context = new Context($backend);

        // A forked child that exits without LoopDriver::afterFork() would otherwise close the inherited streams at
        // shutdown while the inherited Context is still alive (it lives in Revolt's static driver, which PHP frees
        // after the resources), and on epoll each such close deletes the parent's registration.
        $driver = \WeakReference::create($this);
        \register_shutdown_function(static function () use ($driver): void {
            $instance = $driver->get();

            if ($instance !== null && \getmypid() !== $instance->pid) {
                $instance->releaseAfterFork();
            }
        });
        $this->signalQueue = new \SplQueue();
        $this->timerQueue = new TimerQueue();
        $this->zero = Duration::fromSeconds(0);
        $this->rejectCharacterDevices = \PHP_OS_FAMILY === 'Darwin' && $this->context->getBackend() === Backend::Poll;
        $this->signalHandling = \extension_loaded('pcntl')
            && \function_exists('pcntl_signal_dispatch')
            && \function_exists('pcntl_signal');

        $this->streamSelectErrorHandler = function (int $errno, string $message): void {
            if (\stripos($message, 'stream_select(): unable to select [4]: ') === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }

            throw new \Exception($message, $errno);
        };
    }

    public function __destruct()
    {
        if (\getmypid() !== $this->pid) {
            // Inherited through fork(): the signal handlers belonged to the parent's loop (LoopDriver::afterFork()
            // resets them in the child), and the kernel object is not ours to touch.
            if ($this->context?->getBackend() === Backend::Kqueue) {
                self::$orphanedContexts[] = $this->context;
            }

            return;
        }

        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    /**
     * Drops the Context and every watcher without a kernel operation (no remove, no modify), so the driver can no
     * longer reach the descriptor. For {@see \Fledge\Async\EventLoop\LoopDriver::afterFork()} only, which calls it
     * in a forked child before anything else in the child may close an inherited stream: while the inherited Context
     * is alive, closing a stream it watches deletes the parent's epoll registration.
     *
     * In the creating process the Context is simply released (the descriptor closes with it). In another process:
     * on epoll and poll(2) it is released too (closing the child's copy of the epoll descriptor leaves the parent's
     * registrations intact); on kqueue, which a child does not inherit, it is parked until the process exits instead,
     * because closing its descriptor number could close an unrelated descriptor the child has opened since.
     *
     * Afterwards every method that would use the Context throws a LogicException.
     *
     * @internal
     *
     * @return \WeakReference<Context>|null The released Context, to verify that nothing else keeps it alive; null when
     *     there was none or it was parked.
     */
    public function releaseAfterFork(): ?\WeakReference
    {
        $context = $this->context;

        if ($context === null) {
            return null;
        }

        $this->context = null;
        $this->watchers = [];
        $this->watcherInterest = [];
        $this->dirty = [];

        if (\getmypid() !== $this->pid && $context->getBackend() === Backend::Kqueue) {
            self::$orphanedContexts[] = $context;

            return null;
        }

        return \WeakReference::create($context);
    }

    public static function isSupported(): bool
    {
        return Backend::getAvailableBackends() !== [];
    }

    /**
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException('Signal handling requires the pcntl extension');
        }

        return parent::onSignal($signal, $closure);
    }

    /**
     * @return Context|null Null once the driver was released by LoopDriver::afterFork().
     */
    #[\Override]
    public function getHandle(): mixed
    {
        return $this->context;
    }

    #[\Override]
    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    protected function dispatch(bool $blocking): void
    {
        if ($this->signalHandling) {
            \pcntl_signal_dispatch();

            while (!$this->signalQueue->isEmpty()) {
                $signal = $this->signalQueue->dequeue();

                foreach ($this->signalCallbacks[$signal] as $callback) {
                    $this->enqueueCallback($callback);
                }

                $blocking = false;
            }
        }

        if ($this->dirty) {
            $this->sync();
        }

        if ($blocking && $this->watchers) {
            $this->detectClosedStreams();
        }

        $timeout = $blocking ? $this->getTimeout() : 0.0;

        if ($this->readyStreams) {
            $timeout = 0.0;
        }

        $this->wait($timeout);

        if ($this->readyStreams) {
            $this->dispatchReadyStreams();
        }

        $now = $this->now();

        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    #[\Override]
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                $streamId = (int) $callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->streams[$streamId] = $callback->stream;
                $this->dirty[$streamId] = true;
            } elseif ($callback instanceof StreamWritableCallback) {
                $streamId = (int) $callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->streams[$streamId] = $callback->stream;
                $this->dirty[$streamId] = true;
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            \sprintf('Failed to register signal handler; Errno: %d; %s', $errno, $errstr)
                        );
                    });

                    $handler = $this->handleSignal(...);

                    try {
                        \pcntl_signal($callback->signal, $handler);
                    } finally {
                        \restore_error_handler();
                    }
                }

                $this->signalCallbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error('Unknown callback type');
                // @codeCoverageIgnoreEnd
            }
        }
    }

    #[\Override]
    protected function deactivate(DriverCallback $callback): void
    {
        if ($callback instanceof StreamReadableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->readCallbacks[$streamId][$callback->id]);
            if (empty($this->readCallbacks[$streamId])) {
                unset($this->readCallbacks[$streamId]);
                $this->dirty[$streamId] = true;
            }
        } elseif ($callback instanceof StreamWritableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId]);
                $this->dirty[$streamId] = true;
            }
        } elseif ($callback instanceof TimerCallback) {
            $this->timerQueue->remove($callback);
        } elseif ($callback instanceof SignalCallback) {
            if (isset($this->signalCallbacks[$callback->signal])) {
                unset($this->signalCallbacks[$callback->signal][$callback->id]);

                if (empty($this->signalCallbacks[$callback->signal])) {
                    unset($this->signalCallbacks[$callback->signal]);
                    \set_error_handler(static fn () => true);
                    try {
                        \pcntl_signal($callback->signal, \SIG_DFL);
                    } finally {
                        \restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error('Unknown callback type');
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Applies the interest changes collected since the last tick to the backend and the fallback set.
     */
    private function sync(): void
    {
        $context = $this->ownContext();

        foreach ($this->dirty as $streamId => $_) {
            $interest = (isset($this->readCallbacks[$streamId]) ? self::READ : 0)
                | (isset($this->writeCallbacks[$streamId]) ? self::WRITE : 0);

            if ($interest === 0) {
                $this->forget($streamId);

                continue;
            }

            if (isset($this->readyStreams[$streamId]) || isset($this->selectStreams[$streamId])) {
                continue; // Fallback streams take their interest from the callback maps every tick.
            }

            $stream = $this->streams[$streamId];

            if (!\is_resource($stream)) {
                $this->markClosed($streamId);

                continue;
            }

            $watcher = $this->watchers[$streamId] ?? null;

            if ($watcher !== null) {
                if (!$watcher->isActive()) {
                    $this->markClosed($streamId);
                } elseif ($this->watcherInterest[$streamId] !== $interest) {
                    try {
                        $watcher->modifyEvents(self::events($interest));
                        $this->watcherInterest[$streamId] = $interest;
                    } catch (\Throwable) {
                        $this->removeWatcher($streamId);
                        $this->addFallback($streamId, $stream);
                    }
                }

                continue;
            }

            if ($this->rejectCharacterDevices && self::isCharacterDevice($stream)) {
                $this->addFallback($streamId, $stream);

                continue;
            }

            // Context::add() warns before it throws for streams it cannot represent (php://memory).
            \set_error_handler(static fn (): bool => true);

            try {
                $this->watchers[$streamId] = $context->add(
                    new \StreamPollHandle($stream),
                    self::events($interest),
                    $streamId,
                );
                $this->watcherInterest[$streamId] = $interest;
            } catch (\Throwable) {
                $this->addFallback($streamId, $stream);
            } finally {
                \restore_error_handler();
            }
        }

        $this->dirty = [];
    }

    /**
     * @return list<Event>
     */
    private static function events(int $interest): array
    {
        return match ($interest) {
            self::READ => [Event::Read],
            self::WRITE => [Event::Write],
            default => [Event::Read, Event::Write],
        };
    }

    /**
     * @param resource $stream
     */
    private static function isCharacterDevice(mixed $stream): bool
    {
        $stat = @\fstat($stream);

        return \is_array($stat) && ($stat['mode'] & 0170000) === 0020000;
    }

    /**
     * @param resource $stream
     */
    private function addFallback(int $streamId, mixed $stream): void
    {
        if (!\is_resource($stream)) {
            $this->markClosed($streamId);

            return;
        }

        $this->selectStreams[$streamId] = $stream;
    }

    private function markClosed(int $streamId): void
    {
        $this->removeWatcher($streamId);
        unset($this->selectStreams[$streamId]);
        $this->readyStreams[$streamId] = true;
    }

    private function forget(int $streamId): void
    {
        $this->removeWatcher($streamId);
        unset($this->streams[$streamId], $this->selectStreams[$streamId], $this->readyStreams[$streamId]);
    }

    private function removeWatcher(int $streamId): void
    {
        $watcher = $this->watchers[$streamId] ?? null;

        if ($watcher === null) {
            return;
        }

        unset($this->watchers[$streamId], $this->watcherInterest[$streamId]);

        try {
            $watcher->remove();
        } catch (\Throwable) {
            // Already inactive, typically because the stream was closed.
        }
    }

    /**
     * The backend drops a closed descriptor without reporting an event. Moves such streams to the ready set so
     * their callbacks run instead of waiting forever.
     */
    private function detectClosedStreams(): void
    {
        foreach ($this->watchers as $streamId => $watcher) {
            if (!$watcher->isActive()) {
                $this->markClosed($streamId);
            }
        }
    }

    /**
     * @param float $timeout Seconds to wait, 0 for non-blocking, -1 for no limit.
     */
    private function wait(float $timeout): void
    {
        if (!$this->watchers) {
            if ($this->selectStreams) {
                $this->selectFallback($timeout);

                return;
            }

            // Nothing to poll: sleep like StreamSelectDriver. An empty poll(2) context returns at once on a null
            // timeout, so the backend is not used for this. usleep() takes at most UINT32_MAX microseconds (about 71
            // minutes; StreamSelectDriver's PHP_INT_MAX is rejected with a ValueError); a signal ends the sleep early
            // and the next tick simply sleeps again.
            if ($timeout < 0) {
                \usleep(4_294_967_295);
            } elseif ($timeout > 0) {
                \usleep((int) ($timeout * 1_000_000));
            }

            return;
        }

        if ($this->selectStreams && ($timeout < 0 || $timeout > self::FALLBACK_INTERVAL)) {
            $timeout = self::FALLBACK_INTERVAL;
        }

        // Every wait on the Context checks the process first: in a forked child the epoll descriptor is shared, and a
        // wait there can return events the parent registered after the fork, whose data lives in the parent's memory.
        $context = $this->ownContext();

        try {
            $ready = $context->wait(match (true) {
                $timeout < 0 => null,
                $timeout === 0.0 => $this->zero,
                default => Duration::fromNanoseconds((int) \ceil($timeout * 1_000_000_000)),
            });
        } catch (FailedPollWaitException $exception) {
            if ($exception->getCode() !== FailedPollWaitException::ERROR_INTERRUPTED) {
                throw $exception;
            }

            $ready = []; // Interrupted by a signal; it is dispatched at the start of the next tick.
        }

        if ($ready) {
            // Readers first, then writers, matching the order StreamSelectDriver enqueues callbacks in.
            $writable = [];

            foreach ($ready as $watcher) {
                $streamId = $watcher->getData();
                $events = $watcher->getTriggeredEvents();
                $hangup = \in_array(Event::HangUp, $events, true) || \in_array(Event::Error, $events, true);

                if (isset($this->readCallbacks[$streamId])
                    && ($hangup || \in_array(Event::Read, $events, true) || \in_array(Event::ReadHangUp, $events, true))
                ) {
                    foreach ($this->readCallbacks[$streamId] as $callback) {
                        $this->enqueueCallback($callback);
                    }
                }

                if (isset($this->writeCallbacks[$streamId]) && ($hangup || \in_array(Event::Write, $events, true))) {
                    $writable[] = $streamId;
                }
            }

            foreach ($writable as $streamId) {
                foreach ($this->writeCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }
        }

        if ($this->selectStreams) {
            $this->selectFallback(0.0);
        }
    }

    /**
     * Checks the select-checked fallback streams.
     *
     * @param float $timeout Seconds to wait, 0 for non-blocking, -1 for no limit.
     */
    private function selectFallback(float $timeout): void
    {
        $read = [];
        $write = [];

        foreach ($this->selectStreams as $streamId => $stream) {
            if (!\is_resource($stream)) {
                // stream_select() silently skips closed streams; treat them as ready like closed watched streams.
                $this->markClosed($streamId);

                continue;
            }

            if (isset($this->readCallbacks[$streamId])) {
                $read[$streamId] = $stream;
            }

            if (isset($this->writeCallbacks[$streamId])) {
                $write[$streamId] = $stream;
            }
        }

        if ($timeout >= 0) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        } else {
            $seconds = null;
            $microseconds = null;
        }

        if (!$read && !$write) {
            return; // Only closed streams were left; they are dispatched through the ready set.
        }

        $except = null;

        \set_error_handler($this->streamSelectErrorHandler);

        try {
            $result = \stream_select($read, $write, $except, $seconds, $microseconds);
        } catch (\ValueError $exception) {
            // stream_select() dropped every stream it could not represent (php://memory). StreamSelectDriver selects
            // all watched streams in one call, so with other streams in the loop it surfaces the warning turned
            // exception instead; mirror that, and keep the ValueError when there is nothing else.
            if ($this->watchers && $exception->getPrevious() instanceof \Exception) {
                throw $exception->getPrevious();
            }

            throw $exception;
        } finally {
            \restore_error_handler();
        }

        if ($this->streamSelectIgnoreResult || $result === 0) {
            $this->streamSelectIgnoreResult = false;

            return;
        }

        if (!$result) {
            throw new \Exception('Unknown error during stream_select'); // As StreamSelectDriver.
        }

        foreach ($read as $stream) {
            foreach ($this->readCallbacks[(int) $stream] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }

        foreach ($write as $stream) {
            foreach ($this->writeCallbacks[(int) $stream] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }
    }

    private function dispatchReadyStreams(): void
    {
        foreach ($this->readyStreams as $streamId => $_) {
            foreach ($this->readCallbacks[$streamId] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }

        foreach ($this->readyStreams as $streamId => $_) {
            foreach ($this->writeCallbacks[$streamId] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }
    }

    /**
     * @return float Seconds until next timer expires or -1 if there are no pending timers.
     */
    private function getTimeout(): float
    {
        $expiration = $this->timerQueue->peek();

        if ($expiration === null) {
            return -1;
        }

        $expiration -= $this->now();

        return $expiration > 0 ? $expiration : 0.0;
    }

    /**
     * The Context, provided this process created the driver and it was not released.
     *
     * @throws \LogicException In a forked child, or after LoopDriver::afterFork() released the driver.
     */
    private function ownContext(): Context
    {
        if (\getmypid() !== $this->pid) {
            $this->releaseAfterFork(); // Never again touch the descriptor from this process.
        }

        return $this->context ?? throw $this->unusable();
    }

    private function unusable(): \LogicException
    {
        if (\getmypid() !== $this->pid) {
            return new \LogicException(\sprintf(
                'This PollDriver was created in process %d and is used in forked process %d, where its Io\\Poll '
                .'descriptor is shared with or lost from the parent. Call Fledge\\Async\\EventLoop\\LoopDriver::afterFork() '
                .'in the child right after pcntl_fork() to start a fresh event loop.',
                $this->pid,
                \getmypid(),
            ));
        }

        return new \LogicException(
            'This PollDriver was released by Fledge\\Async\\EventLoop\\LoopDriver::afterFork() and can no longer be used; '
            .'use the driver afterFork() installed (Revolt\\EventLoop::getDriver()).',
        );
    }

    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs.
        $this->signalQueue->enqueue($signal);
    }
}
