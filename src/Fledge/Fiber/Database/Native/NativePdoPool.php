<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Closure;
use Countable;
use InvalidArgumentException;
use LogicException;
use PDO;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use RuntimeException;
use SplObjectStorage;
use Throwable;
use UnexpectedValueException;
use WeakReference;

/**
 * Pool of real pdo_mysql connections, one leased per fiber.
 *
 * - At most `$size` connections are open (idle + leased + being opened).
 * - Connections are created lazily by the factory, on demand.
 * - Idle connections older than `$idleTimeout` seconds are closed: on every
 *   acquire, and by an unreferenced Revolt timer while any sit idle.
 * - A fiber finding the pool exhausted parks on a Revolt Suspension. Waiters
 *   are served strictly FIFO: a released connection is handed directly to the
 *   oldest waiter, and a discarded one passes its slot to the oldest waiter,
 *   which then opens a fresh connection.
 * - A waiter gives up after `$waitTimeout` seconds with a RuntimeException, so
 *   a leaked lease surfaces as an error instead of hanging the worker. A
 *   waiter that gave up is never handed a connection afterwards.
 * - release() returns a connection; discard() drops it. A connection that is
 *   still inside a transaction, or whose LeaseGuard reports it broken, is
 *   discarded on release instead of reused.
 */
final class NativePdoPool implements Countable
{
    private readonly Closure $factory;

    private readonly Closure $clock;

    /**
     * Idle connections with the time they were released, oldest first.
     *
     * @var list<array{PDO, float}>
     */
    private array $idle = [];

    /** @var SplObjectStorage<PDO, null> */
    private SplObjectStorage $leased;

    /**
     * Parked fibers keyed by a monotonically increasing id, oldest first.
     *
     * @var array<int, array{Suspension, object}>
     */
    private array $waiters = [];

    private int $nextWaiterId = 0;

    /**
     * Open connections plus slots reserved for connections being opened.
     */
    private int $open = 0;

    private bool $closed = false;

    private ?string $reapTimer = null;

    /**
     * @param  Closure(): PDO  $factory  Opens one physical connection.
     * @param  int  $size  Maximum number of open connections.
     * @param  float  $idleTimeout  Seconds an idle connection may live; 0 disables reaping.
     * @param  (Closure(): float)|null  $clock  Monotonic clock in seconds for idle reaping (tests inject a fake).
     * @param  float  $waitTimeout  Seconds a fiber may wait on an exhausted pool before a RuntimeException.
     */
    public function __construct(
        Closure $factory,
        private readonly int $size = 32,
        private readonly float $idleTimeout = 60.0,
        ?Closure $clock = null,
        private readonly float $waitTimeout = 30.0,
    ) {
        if ($size < 1) {
            throw new InvalidArgumentException("pool_size must be at least 1, got {$size}.");
        }

        if ($waitTimeout <= 0) {
            throw new InvalidArgumentException("pool_wait_timeout must be greater than 0, got {$waitTimeout}.");
        }

        $this->factory = $factory;
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->leased = new SplObjectStorage;
    }

    public function __destruct()
    {
        $this->cancelReapTimer();
    }

    /**
     * Lease a connection to the current fiber (or the main context).
     *
     * Suspends the caller while the pool is exhausted.
     *
     * @throws RuntimeException when the pool is closed (also while waiting), or the wait timed out
     * @throws Throwable whatever the factory throws when opening a connection
     */
    public function acquire(): PDO
    {
        if ($this->closed) {
            throw new RuntimeException('The native PDO pool is closed.');
        }

        $this->reap();

        if ($this->idle !== []) {
            [$pdo] = array_pop($this->idle);

            return $this->lease($pdo, LeaseGuard::currentOwner());
        }

        if ($this->open < $this->size) {
            $this->open++;

            return $this->openConnection(LeaseGuard::currentOwner());
        }

        return $this->wait();
    }

    /**
     * Return a leased connection to the pool.
     *
     * @throws LogicException when the PDO is not currently leased from this pool
     */
    public function release(PDO $pdo): void
    {
        $this->detach($pdo);

        if ($this->closed || ! $this->isReusable($pdo)) {
            $this->freeSlot();

            return;
        }

        $waiter = $this->dequeueWaiter();

        if ($waiter !== null) {
            [$suspension, $owner] = $waiter;

            $suspension->resume($this->lease($pdo, $owner));

            return;
        }

        $this->idle[] = [$pdo, ($this->clock)()];

        $this->scheduleReap();
    }

    /**
     * Drop a leased connection; its slot goes to the oldest waiter.
     *
     * @throws LogicException when the PDO is not currently leased from this pool
     */
    public function discard(PDO $pdo): void
    {
        $this->detach($pdo);

        $this->freeSlot();
    }

    /**
     * Close idle connections that exceeded the idle timeout.
     *
     * @return int The number of connections closed.
     */
    public function reap(): int
    {
        if ($this->idleTimeout <= 0 || $this->idle === []) {
            return 0;
        }

        $now = ($this->clock)();
        $reaped = 0;

        while ($this->idle !== [] && $now - $this->idle[0][1] >= $this->idleTimeout) {
            array_shift($this->idle);
            $this->freeSlot();
            $reaped++;
        }

        if ($this->idle === []) {
            $this->cancelReapTimer();
        }

        return $reaped;
    }

    /**
     * Close the pool: idle connections are dropped, waiters get a
     * RuntimeException, and leased connections are discarded on release.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->open -= count($this->idle);
        $this->idle = [];
        $this->cancelReapTimer();

        while (($waiter = $this->dequeueWaiter()) !== null) {
            [$suspension] = $waiter;

            $suspension->throw(new RuntimeException('The native PDO pool was closed while waiting for a connection.'));
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Whether the PDO is currently leased from this pool.
     */
    public function isLeased(PDO $pdo): bool
    {
        return $this->leased->offsetExists($pdo);
    }

    /**
     * Open connections (idle + leased + being opened).
     */
    public function count(): int
    {
        return $this->open;
    }

    public function getMaxSize(): int
    {
        return $this->size;
    }

    public function getIdleTimeout(): float
    {
        return $this->idleTimeout;
    }

    public function getIdleCount(): int
    {
        return count($this->idle);
    }

    public function getLeasedCount(): int
    {
        return $this->leased->count();
    }

    public function getWaitingCount(): int
    {
        return count($this->waiters);
    }

    public function getWaitTimeout(): float
    {
        return $this->waitTimeout;
    }

    private function wait(): PDO
    {
        $owner = LeaseGuard::currentOwner();
        $suspension = EventLoop::getSuspension();
        $id = $this->nextWaiterId++;

        $this->waiters[$id] = [$suspension, $owner];

        // Fires only while we are still queued: once dequeued by release(),
        // discard() or close(), our entry is gone and the timer is a no-op.
        $timer = EventLoop::delay($this->waitTimeout, function () use ($id, $suspension): void {
            if (! isset($this->waiters[$id])) {
                return;
            }

            unset($this->waiters[$id]);

            $suspension->throw($this->waitTimeoutException());
        });

        try {
            // Resumed with a PDO already leased to us, or with null: a slot was
            // freed and reserved for us, so we open the connection ourselves.
            $result = $suspension->suspend();
        } catch (Throwable $e) {
            // Timed out, pool closed, or anything else thrown into us: make
            // sure a later release never hands a connection to a gone waiter.
            unset($this->waiters[$id]);

            throw $e;
        } finally {
            EventLoop::cancel($timer);
        }

        return $result ?? $this->openConnection($owner);
    }

    /**
     * Remove and return the oldest waiter, if any.
     *
     * @return array{Suspension, object}|null
     */
    private function dequeueWaiter(): ?array
    {
        $id = array_key_first($this->waiters);

        if ($id === null) {
            return null;
        }

        $waiter = $this->waiters[$id];
        unset($this->waiters[$id]);

        return $waiter;
    }

    private function waitTimeoutException(): RuntimeException
    {
        $leased = $this->leased->count();
        $waiting = count($this->waiters);

        return new RuntimeException(
            "Timed out after {$this->waitTimeout}s waiting for a connection from the native PDO pool: "
            ."the pool is exhausted ({$leased} of {$this->size} connections leased, {$waiting} other fibers still waiting). "
            .'This usually means leases are leaked: a transaction awaiting child fibers that query the same connection, '
            .'a cursor() generator that was never fully consumed, or a beginTransaction() never committed or rolled back. '
            .'Raise pool_size or pool_wait_timeout only if the load is genuine.'
        );
    }

    /**
     * Open a connection in a slot already counted in $open.
     */
    private function openConnection(object $owner): PDO
    {
        try {
            $pdo = ($this->factory)();
        } catch (Throwable $e) {
            $this->freeSlot();

            throw $e;
        }

        if (! $pdo instanceof PDO) {
            $this->freeSlot();

            throw new UnexpectedValueException('The native PDO pool factory must return a PDO instance, got '.get_debug_type($pdo).'.');
        }

        return $this->lease($pdo, $owner);
    }

    private function lease(PDO $pdo, object $owner): PDO
    {
        $this->leased->offsetSet($pdo);

        if ($pdo instanceof GuardedPdo) {
            $pdo->leaseGuard()->assign($owner);
        }

        return $pdo;
    }

    private function detach(PDO $pdo): void
    {
        if (! $this->leased->offsetExists($pdo)) {
            throw new LogicException('This PDO is not leased from this pool: it was released twice, discarded, or belongs to another pool.');
        }

        $this->leased->offsetUnset($pdo);

        if ($pdo instanceof GuardedPdo) {
            $pdo->leaseGuard()->clear();
        }
    }

    /**
     * A slot became free: pass it to the oldest waiter, or shrink the pool.
     */
    private function freeSlot(): void
    {
        $waiter = $this->closed ? null : $this->dequeueWaiter();

        if ($waiter !== null) {
            [$suspension] = $waiter;

            $suspension->resume(null);

            return;
        }

        $this->open--;
    }

    private function isReusable(PDO $pdo): bool
    {
        if ($pdo instanceof GuardedPdo && $pdo->leaseGuard()->isBroken()) {
            return false;
        }

        try {
            return ! $pdo->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    private function scheduleReap(): void
    {
        if ($this->reapTimer !== null || $this->idleTimeout <= 0) {
            return;
        }

        $pool = WeakReference::create($this);

        $this->reapTimer = EventLoop::unreference(EventLoop::repeat(
            max(0.1, $this->idleTimeout / 2),
            static function (string $id) use ($pool): void {
                $instance = $pool->get();

                if ($instance === null) {
                    EventLoop::cancel($id);

                    return;
                }

                $instance->reap();
            },
        ));
    }

    private function cancelReapTimer(): void
    {
        if ($this->reapTimer === null) {
            return;
        }

        try {
            EventLoop::cancel($this->reapTimer);
        } catch (Throwable) {
            // The loop may already be gone during shutdown.
        }

        $this->reapTimer = null;
    }
}
