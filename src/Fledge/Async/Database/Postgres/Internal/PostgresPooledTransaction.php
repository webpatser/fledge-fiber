<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresExecutor;
use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\Postgres\PostgresTransaction;
use Fledge\Async\Database\SqlPooledTransaction;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;

/**
 * @internal
 * @extends SqlPooledTransaction<PostgresResult, PostgresStatement, PostgresTransaction>
 */
final class PostgresPooledTransaction extends SqlPooledTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    /**
     * @param \Closure():void $release
     * @param PostgresSessionTracker|null $tracker Records what the statements did to the session
     *     (shared with nested transactions), so the pool resets the connection only when needed.
     * @param bool $topLevel False for a nested transaction (savepoint), whose end does not end the session's transaction.
     */
    public function __construct(
        private readonly PostgresTransaction $transaction,
        \Closure $release,
        private readonly ?PostgresSessionTracker $tracker = null,
        private readonly bool $topLevel = true,
    ) {
        parent::__construct($transaction, $release);
    }

    #[\Override]
    protected function getExecutor(): PostgresExecutor
    {
        return $this->transaction;
    }

    #[\Override]
    protected function createTransaction(SqlTransaction $transaction, \Closure $release): PostgresTransaction
    {
        \assert($transaction instanceof PostgresTransaction);
        return new PostgresPooledTransaction($transaction, $release, $this->tracker, false);
    }

    /**
     * @param \Closure():void $release
     */
    protected function createStatement(
        SqlStatement $statement,
        \Closure $release,
        ?\Closure $awaitBusyResource = null,
    ): PostgresStatement {
        \assert($statement instanceof PostgresStatement);
        $tracker = $this->tracker;

        return new PostgresPooledStatement(
            $statement,
            $release,
            $awaitBusyResource,
            $tracker === null ? null : static fn () => $tracker->fail(),
        );
    }

    public function query(string $sql): PostgresResult
    {
        $this->tracker?->note($sql);

        try {
            return parent::query($sql);
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }
    }

    public function prepare(string $sql): PostgresStatement
    {
        $this->tracker?->note($sql);

        try {
            return parent::prepare($sql);
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        $this->tracker?->note($sql);

        try {
            return parent::execute($sql, $params);
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }
    }

    /**
     * NOTIFY is transaction-scoped (delivered on commit), but a failed one aborts the transaction.
     */
    public function notify(string $channel, string $payload = ""): PostgresResult
    {
        try {
            return $this->transaction->notify($channel, $payload);
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }
    }

    public function beginTransaction(): PostgresTransaction
    {
        try {
            return parent::beginTransaction();
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }

        if ($this->topLevel) {
            $this->tracker?->end();
        }
    }

    public function rollback(): void
    {
        try {
            parent::rollback();
        } catch (\Throwable $exception) {
            $this->tracker?->fail();
            throw $exception;
        }

        if ($this->topLevel) {
            $this->tracker?->end();
        }
    }
}
