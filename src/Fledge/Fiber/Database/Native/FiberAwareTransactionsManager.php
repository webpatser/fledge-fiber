<?php

namespace Fledge\Fiber\Database\Native;

use Fiber;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * Transactions manager that keeps the transaction records and after-commit /
 * after-rollback callbacks of fiber-scoped (native) connections per fiber.
 *
 * Laravel's DatabaseTransactionsManager tracks one stack per connection name.
 * The native connections keep their transaction level per fiber, so fiber B
 * committing its own transaction would run fiber A's afterCommit callbacks
 * (and a rollback to level 0 in B would drop them). Routing:
 *
 * - begin / commit / stageTransactions / rollback for a connection that
 *   registered itself as fiber-scoped (scopeConnectionPerFiber()) go to the
 *   calling fiber's own inner manager. Every other connection, and the main
 *   context, delegates straight to the wrapped original manager.
 * - The connection-less calls (addCallback(), queued jobs, broadcasts,
 *   Eloquent after-commit events) use the fiber's own manager only while that
 *   fiber has pending transactions; otherwise they delegate to the original.
 *   A fiber outside its own transaction never attaches callbacks to a main
 *   context transaction on a fiber-scoped connection: that transaction runs on
 *   a different pooled connection, so the fiber's work is already committed.
 *
 * With no fiber-scoped connection registered every call reaches the original
 * manager unchanged, so the container binding is wrapped unconditionally and a
 * native connection added at runtime (config set after boot) still works.
 */
class FiberAwareTransactionsManager extends DatabaseTransactionsManager
{
    /** @var WeakMap<Fiber, DatabaseTransactionsManager> */
    private WeakMap $fiberManagers;

    /** @var array<string, true> */
    private array $fiberScopedConnections = [];

    public function __construct(private readonly DatabaseTransactionsManager $mainManager)
    {
        parent::__construct();

        $this->fiberManagers = new WeakMap;
    }

    public static function wrap(DatabaseTransactionsManager $manager): self
    {
        return $manager instanceof self ? $manager : new self($manager);
    }

    /**
     * The manager the main context (and every non fiber-scoped connection)
     * delegates to.
     */
    public function getMainManager(): DatabaseTransactionsManager
    {
        return $this->mainManager;
    }

    /**
     * Keep the transaction records of the given connection per fiber.
     */
    public function scopeConnectionPerFiber(?string $connection): void
    {
        $this->fiberScopedConnections[(string) $connection] = true;
    }

    public function isScopedPerFiber(?string $connection): bool
    {
        return isset($this->fiberScopedConnections[(string) $connection]);
    }

    /**
     * The manager connection-less calls go to: the current fiber's own
     * manager while it has pending transactions, otherwise the original.
     */
    public function current(): DatabaseTransactionsManager
    {
        return $this->ownFiberManager() ?? $this->mainManager;
    }

    public function begin($connection, $level)
    {
        $this->managerFor($connection)->begin($connection, $level);
    }

    public function commit($connection, $levelBeingCommitted, $newTransactionLevel)
    {
        $committed = $this->managerFor($connection)->commit($connection, $levelBeingCommitted, $newTransactionLevel);

        $this->forgetIdleFiber();

        return $committed;
    }

    public function stageTransactions($connection, $levelBeingCommitted)
    {
        $this->managerFor($connection)->stageTransactions($connection, $levelBeingCommitted);
    }

    public function rollback($connection, $newTransactionLevel)
    {
        $this->managerFor($connection)->rollback($connection, $newTransactionLevel);

        $this->forgetIdleFiber();
    }

    public function addCallback($callback)
    {
        $applicable = $this->filteredMainTransactions();

        if ($applicable === null) {
            return $this->current()->addCallback($callback);
        }

        if ($current = $applicable->last()) {
            return $current->addCallback($callback);
        }

        $callback();
    }

    public function addCallbackForRollback($callback)
    {
        $applicable = $this->filteredMainTransactions();

        if ($applicable === null) {
            return $this->current()->addCallbackForRollback($callback);
        }

        if ($current = $applicable->last()) {
            return $current->addCallbackForRollback($callback);
        }
    }

    public function callbackApplicableTransactions()
    {
        return $this->filteredMainTransactions() ?? $this->current()->callbackApplicableTransactions();
    }

    public function afterCommitCallbacksShouldBeExecuted($level)
    {
        return $this->current()->afterCommitCallbacksShouldBeExecuted($level);
    }

    public function getPendingTransactions()
    {
        return $this->current()->getPendingTransactions();
    }

    public function getCommittedTransactions()
    {
        return $this->current()->getCommittedTransactions();
    }

    /**
     * The manager for begin/commit/rollback on the given connection.
     */
    private function managerFor(?string $connection): DatabaseTransactionsManager
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null || ! $this->isScopedPerFiber($connection)) {
            return $this->mainManager;
        }

        return $this->fiberManagers[$fiber] ??= new DatabaseTransactionsManager;
    }

    /**
     * The current fiber's own manager, when it has pending transactions.
     */
    private function ownFiberManager(): ?DatabaseTransactionsManager
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return null;
        }

        $manager = $this->fiberManagers[$fiber] ?? null;

        return $manager !== null && $manager->getPendingTransactions()->isNotEmpty() ? $manager : null;
    }

    /**
     * For a fiber without transactions of its own: the main context's
     * callback-applicable transactions minus those on fiber-scoped
     * connections. Null when nothing has to be skipped, so the call can
     * delegate to current() unchanged.
     *
     * @return Collection<int, DatabaseTransactionRecord>|null
     */
    private function filteredMainTransactions(): ?Collection
    {
        if ($this->fiberScopedConnections === [] || Fiber::getCurrent() === null || $this->ownFiberManager() !== null) {
            return null;
        }

        $applicable = $this->mainManager->callbackApplicableTransactions();

        $filtered = $applicable
            ->reject(fn (DatabaseTransactionRecord $transaction) => $this->isScopedPerFiber($transaction->connection))
            ->values();

        return $filtered->count() === $applicable->count() ? null : $filtered;
    }

    /**
     * Drop the current fiber's inner manager once it holds nothing, so a
     * fiber Revolt reuses for a later callback starts clean.
     */
    private function forgetIdleFiber(): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null || ! isset($this->fiberManagers[$fiber])) {
            return;
        }

        $manager = $this->fiberManagers[$fiber];

        if ($manager->getPendingTransactions()->isEmpty() && $manager->getCommittedTransactions()->isEmpty()) {
            unset($this->fiberManagers[$fiber]);
        }
    }
}
