<?php

namespace Fledge\Fiber\Database\Native;

use Closure;
use Fiber;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Throwable;
use WeakMap;

/**
 * Connection behaviour of the native drivers: a NativePdoPool in the raw PDO
 * slots and one leased PDO per fiber.
 *
 * Pools live in the raw `$pdo` / `$readPdo` / `$directPdo` slots (resolved
 * lazily from the factory Closure like a stock PDO), so getRawPdo(),
 * DatabaseManager::reconnect() and disconnect() keep working. getPdo(),
 * getReadPdo() and getDirectPdo() return the PDO leased to the current fiber;
 * the main context has a lease of its own.
 *
 * Lease scope (keyed on the current fiber plus a scope depth, never on fiber
 * lifetime, because Revolt reuses callback fibers):
 * - run() and the transaction methods open a scope; the outermost scope
 *   releases the fiber's leases in `finally` when its transaction level is 0.
 * - A transaction pins the lease until the final commit or rollback.
 * - cursor() holds a scope until its generator ends (or is destroyed).
 * - A PDO taken outside any scope (DB::getPdo(), isMaria(), escape()) stays
 *   leased until that fiber's next outermost scope ends.
 *
 * Transaction levels are per fiber: `$transactions` is a hooked property
 * backed by the fiber's LeaseScope. setPdo() (disconnect, DatabaseManager
 * reconnect) resets only the calling fiber. Another fiber with an open
 * transaction keeps its level, and its write lease is marked broken, so its
 * next call throws LostConnectionException and the rollback drops the lease:
 * that transaction fails loudly instead of skipping its COMMIT. The
 * lost-connection paths (reconnect(), the commit/rollback handlers) touch
 * only the calling fiber too.
 *
 * `$lastInsertId` is per fiber as well (same hooked-property pattern), so an
 * insertGetId() never reads the id of another fiber's insert.
 *
 * The transactions manager is wrapped in FiberAwareTransactionsManager and
 * this connection registers itself as fiber-scoped there, so afterCommit and
 * afterRollBack callbacks follow the fiber's own transaction.
 *
 * @mixin Connection
 */
trait UsesNativePdoPool
{
    /** @var WeakMap<Fiber, LeaseScope> */
    private WeakMap $fiberLeaseScopes;

    private ?LeaseScope $mainLeaseScope = null;

    /** @var WeakMap<Fiber, string|int|false|null> */
    private WeakMap $fiberLastInsertIds;

    /**
     * The last insert id of the current fiber (or the main context, which
     * uses the backing slot).
     *
     * @var string|int|null
     */
    protected $lastInsertId = null {
        get {
            $fiber = Fiber::getCurrent();

            return $fiber === null ? $this->lastInsertId : ($this->fiberLastInsertIds[$fiber] ?? null);
        }
        set {
            $fiber = Fiber::getCurrent();

            if ($fiber === null) {
                $this->lastInsertId = $value;

                return;
            }

            $this->fiberLastInsertIds ??= new WeakMap;
            $this->fiberLastInsertIds[$fiber] = $value;
        }
    }

    /**
     * The transaction level of the current fiber (or the main context).
     *
     * Every ManagesTransactions method keeps working unchanged because they
     * all go through `$this->transactions`. The level lives in the fiber's
     * LeaseScope; the backing slot only mirrors the main context's level.
     *
     * @var int
     */
    protected $transactions = 0 {
        get => $this->leaseScope(false)?->transactions ?? 0;
        set {
            if (Fiber::getCurrent() === null) {
                $this->transactions = $value;
            }

            $scope = $this->leaseScope($value !== 0);

            if ($scope !== null) {
                $scope->transactions = $value;
            }
        }
    }

    /**
     * Get the PDO leased to the current fiber.
     *
     * @return \PDO
     */
    public function getPdo()
    {
        $this->latestPdoTypeRetrieved = 'write';

        if ($this->pdo === null && $this->transactions > 0) {
            throw $this->closedTransactionException();
        }

        if ($this->pdo instanceof Closure) {
            $this->pdo = call_user_func($this->pdo);
        }

        return $this->pdo instanceof NativePdoPool
            ? $this->leasedPdo('write', $this->pdo)
            : $this->pdo;
    }

    /**
     * Get the read PDO leased to the current fiber.
     *
     * @return \PDO
     */
    public function getReadPdo()
    {
        if ($this->transactions > 0) {
            return $this->getPdo();
        }

        if ($this->readOnWriteConnection ||
            ($this->recordsModified && $this->getConfig('sticky'))) {
            return $this->getPdo();
        }

        $this->latestPdoTypeRetrieved = 'read';

        if ($this->readPdo instanceof Closure) {
            $this->readPdo = call_user_func($this->readPdo);
        }

        if ($this->readPdo instanceof NativePdoPool) {
            return $this->leasedPdo('read', $this->readPdo);
        }

        return $this->readPdo ?: $this->getPdo();
    }

    /**
     * Get the direct PDO leased to the current fiber.
     *
     * @return \PDO
     */
    public function getDirectPdo()
    {
        $this->latestPdoTypeRetrieved = 'direct';

        if ($this->directPdo instanceof Closure) {
            $this->directPdo = call_user_func($this->directPdo);
        }

        if ($this->directPdo instanceof NativePdoPool) {
            return $this->leasedPdo('direct', $this->directPdo);
        }

        return $this->directPdo ?: $this->getPdo();
    }

    /**
     * Replace the write PDO (pool) and close the previous pool.
     *
     * Only the calling fiber's transaction level is reset (stock semantics for
     * the caller). Other fibers keep theirs: their open transactions die with
     * the old pool, so their write leases are marked broken and their next
     * call throws LostConnectionException instead of silently skipping the
     * COMMIT. Leases other fibers hold outside a transaction are released to
     * the closed pool (which drops them) when their scopes end.
     *
     * @param  \PDO|Closure|NativePdoPool|null  $pdo
     * @return $this
     */
    public function setPdo($pdo)
    {
        $previous = $this->pdo;

        $this->transactions = 0;
        $this->releaseCurrentLease('write');

        if ($previous !== $pdo) {
            $this->breakOtherOpenTransactions();
        }

        $this->pdo = $pdo;

        if ($previous instanceof NativePdoPool && $previous !== $pdo) {
            $previous->close();
        }

        return $this;
    }

    /**
     * @param  \PDO|Closure|NativePdoPool|null  $pdo
     * @return $this
     */
    public function setReadPdo($pdo)
    {
        $previous = $this->readPdo;

        $this->releaseCurrentLease('read');

        $this->readPdo = $pdo;

        if ($previous instanceof NativePdoPool && $previous !== $pdo) {
            $previous->close();
        }

        return $this;
    }

    /**
     * @param  \PDO|Closure|NativePdoPool|null  $pdo
     * @return $this
     */
    public function setDirectPdo($pdo)
    {
        $previous = $this->directPdo;

        $this->releaseCurrentLease('direct');

        $this->directPdo = $pdo;

        if ($previous instanceof NativePdoPool && $previous !== $pdo) {
            $previous->close();
        }

        return $this;
    }

    /**
     * A missing PDO (after disconnect()) is rebuilt through the reconnector,
     * except inside a transaction: that transaction died with the old pool.
     *
     * @return void
     *
     * @throws LostConnectionException when the calling fiber has an open transaction
     */
    public function reconnectIfMissingConnection()
    {
        if ($this->pdo === null && $this->transactions > 0) {
            throw $this->closedTransactionException();
        }

        parent::reconnectIfMissingConnection();
    }

    /**
     * Lost connection: drop only the calling fiber's broken lease.
     *
     * The stock reconnect() rebuilds the whole connection through the
     * DatabaseManager, which would tear down every other fiber's connection
     * and transaction. Here the next getPdo() simply leases a fresh PDO.
     *
     * @return $this|mixed
     */
    public function reconnect()
    {
        if (! $this->pdo instanceof NativePdoPool) {
            return parent::reconnect();
        }

        $scope = $this->leaseScope(false);
        $slot = $scope?->lastSlot ?? 'write';

        if ($scope !== null && isset($scope->leases[$slot])) {
            if ($slot === 'write' && $scope->transactions > 0) {
                $this->transactions = 0;
                $this->transactionsManager?->rollback($this->getName(), 0);
            }

            $lease = $scope->leases[$slot];
            unset($scope->leases[$slot]);
            $lease->discard();
        }

        return $this;
    }

    /**
     * Wrap the manager and register this connection as fiber-scoped, so its
     * transaction records and callbacks stay per fiber.
     *
     * @param  DatabaseTransactionsManager  $manager
     * @return $this
     */
    public function setTransactionManager($manager)
    {
        if ($manager instanceof DatabaseTransactionsManager) {
            $manager = FiberAwareTransactionsManager::wrap($manager);
            $manager->scopeConnectionPerFiber($this->getName());
        }

        $this->transactionsManager = $manager;

        return $this;
    }

    /**
     * A commit that failed for good (a broken lease or closed pool, a server
     * error such as 2006, a deadlock after the last attempt) leaves this
     * fiber's transaction level at 0 and throws. Its transaction records are
     * rolled back too, whatever the exception type: otherwise they linger in
     * the fiber's manager, the fiber is never forgotten there, and on a fiber
     * Revolt reuses a later afterCommit callback or after-commit job attaches
     * to the dead record and fires on an unrelated commit.
     *
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     *
     * @throws Throwable
     */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        try {
            parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);
        } catch (Throwable $thrown) {
            if ($this->transactions === 0) {
                // The fiber-aware manager forgets the fiber once it holds nothing.
                $this->transactionsManager?->rollback($this->getName(), 0);
            }

            throw $thrown;
        }
    }

    /**
     * Run a SQL statement inside a lease scope.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed
     *
     * @throws QueryException
     * @throws LeaseOwnershipException
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $scope = $this->enterLeaseScope();

        try {
            return parent::run($query, $bindings, $callback);
        } catch (QueryException $e) {
            // A lease-owner violation is a programming error, not a query failure.
            throw $e->getPrevious() instanceof LeaseOwnershipException ? $e->getPrevious() : $e;
        } finally {
            $this->exitLeaseScope($scope);
        }
    }

    /**
     * Run a select statement and return a generator that holds the fiber's
     * lease until it ends.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return \Generator<int, \stdClass>
     *
     * @throws LeaseOwnershipException when resumed by a fiber other than the one that started it
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $scope = $this->enterLeaseScope();
        $owner = LeaseGuard::currentOwner();

        try {
            $records = parent::cursor($query, $bindings, $useReadPdo, $fetchUsing);

            foreach ($records as $record) {
                yield $record;

                if (LeaseGuard::currentOwner() !== $owner) {
                    throw new LeaseOwnershipException(
                        'A cursor() generator must be consumed by the fiber that started it: its connection is leased to that fiber.'
                    );
                }
            }
        } finally {
            unset($records);

            // May run from another context when the generator is destroyed
            // early: the scope object, not the current fiber, identifies the owner.
            $this->exitLeaseScope($scope);
        }
    }

    /**
     * @template TReturn of mixed
     *
     * @param  (Closure(static): TReturn)  $callback
     * @param  int  $attempts
     * @return TReturn
     *
     * @throws Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        $scope = $this->enterLeaseScope();

        try {
            return parent::transaction($callback, $attempts);
        } finally {
            $this->exitLeaseScope($scope);
        }
    }

    /**
     * @return void
     *
     * @throws Throwable
     */
    public function beginTransaction()
    {
        $scope = $this->enterLeaseScope();

        try {
            parent::beginTransaction();
        } finally {
            $this->exitLeaseScope($scope);
        }
    }

    /**
     * @return void
     *
     * @throws Throwable
     */
    public function commit()
    {
        $scope = $this->enterLeaseScope();

        try {
            parent::commit();
        } finally {
            $this->exitLeaseScope($scope);
        }
    }

    /**
     * @param  int|null  $toLevel
     * @return void
     *
     * @throws Throwable
     */
    public function rollBack($toLevel = null)
    {
        $scope = $this->enterLeaseScope();

        try {
            parent::rollBack($toLevel);
        } finally {
            $this->exitLeaseScope($scope);
        }
    }

    /**
     * Roll back; a broken connection is dropped instead (closing it makes the
     * server roll back the whole transaction).
     *
     * @param  int  $toLevel
     * @return void
     *
     * @throws Throwable
     */
    protected function performRollBack($toLevel)
    {
        $scope = $this->leaseScope(false);
        $lease = $scope?->leases['write'] ?? null;

        if ($lease === null || ! $lease->isBroken()) {
            parent::performRollBack($toLevel);

            return;
        }

        unset($scope->leases['write']);
        $lease->discard();

        if ($toLevel > 0) {
            throw new LostConnectionException(
                'Lost connection: the pooled connection was interrupted mid-call, so the whole transaction '
                ."was rolled back and savepoint trans{$toLevel} no longer exists."
            );
        }
    }

    /**
     * Mark the write lease of every other fiber (and the main context) with an
     * open transaction broken: its connection is being closed or replaced, so
     * the server rolls that transaction back. The level stays, so the owner's
     * next call throws LostConnectionException and its rollback drops the lease.
     */
    protected function breakOtherOpenTransactions(): void
    {
        $current = $this->leaseScope(false);
        $scopes = $this->mainLeaseScope !== null ? [$this->mainLeaseScope] : [];

        if (isset($this->fiberLeaseScopes)) {
            foreach ($this->fiberLeaseScopes as $scope) {
                $scopes[] = $scope;
            }
        }

        foreach ($scopes as $scope) {
            if ($scope === $current || $scope->transactions === 0) {
                continue;
            }

            $pdo = ($scope->leases['write'] ?? null)?->pdo;

            if ($pdo instanceof GuardedPdo) {
                $pdo->leaseGuard()->markBroken();
            }
        }
    }

    /**
     * A clone starts without leases, transaction levels or insert ids of its own.
     */
    public function __clone()
    {
        parent::__clone();

        unset($this->fiberLeaseScopes, $this->fiberLastInsertIds);
        $this->mainLeaseScope = null;
    }

    private function closedTransactionException(): LostConnectionException
    {
        return new LostConnectionException(
            'Lost connection: the connection was closed (disconnect or reconnect) while this transaction '
            .'was open, so the server rolled it back. Roll the transaction back.'
        );
    }

    /**
     * The PDO leased to the current fiber for the given slot, acquiring one
     * from the pool (possibly suspending) when the fiber has none.
     *
     * @throws LostConnectionException when the fiber's transaction connection is broken
     */
    private function leasedPdo(string $slot, NativePdoPool $pool): \PDO
    {
        $scope = $this->leaseScope(true);
        $scope->lastSlot = $slot;

        $lease = $scope->leases[$slot] ?? null;

        if ($lease !== null) {
            if (! $lease->isBroken()) {
                return $lease->pdo;
            }

            // Keep a broken transaction connection pinned until the caller
            // rolls back (performRollBack drops it), exactly like a lost
            // connection inside a transaction: never continue silently on a
            // fresh connection outside the transaction.
            if ($slot === 'write' && $scope->transactions > 0) {
                throw new LostConnectionException(
                    'Lost connection: the pooled connection of this transaction was interrupted mid-call or '
                    .'closed by a disconnect or reconnect, and cannot be reused. Roll the transaction back.'
                );
            }

            unset($scope->leases[$slot]);
            $lease->discard();
        }

        $pdo = $pool->acquire();

        $scope->leases[$slot] = new Lease($pool, $pdo);

        return $pdo;
    }

    private function leaseScope(bool $create): ?LeaseScope
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return $create ? ($this->mainLeaseScope ??= new LeaseScope) : $this->mainLeaseScope;
        }

        $this->fiberLeaseScopes ??= new WeakMap;

        return $create
            ? ($this->fiberLeaseScopes[$fiber] ??= new LeaseScope)
            : ($this->fiberLeaseScopes[$fiber] ?? null);
    }

    private function enterLeaseScope(): LeaseScope
    {
        $scope = $this->leaseScope(true);
        $scope->depth++;

        return $scope;
    }

    private function exitLeaseScope(LeaseScope $scope): void
    {
        if (--$scope->depth > 0 || $scope->transactions > 0) {
            return;
        }

        $leases = $scope->leases;
        $scope->leases = [];
        $scope->lastSlot = null;

        foreach ($leases as $lease) {
            $lease->release();
        }
    }

    private function releaseCurrentLease(string $slot): void
    {
        $scope = $this->leaseScope(false);

        if ($scope === null || ! isset($scope->leases[$slot])) {
            return;
        }

        $lease = $scope->leases[$slot];
        unset($scope->leases[$slot]);
        $lease->release();
    }
}
