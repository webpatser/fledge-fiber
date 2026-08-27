<?php

namespace Tests\Fledge\database\Stubs;

use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Async\Database\SqlTransactionIsolation;
use Fledge\Async\Database\SqlTransactionIsolationLevel;

/**
 * In-memory SqlConnection that records executed queries for unit tests.
 */
class FakeSqlConnection implements SqlConnection
{
    /** @var list<string> */
    public array $queries = [];

    public bool $closed = false;

    public function __construct(
        private SqlConfig $config,
        private ?string $failOn = null,
    ) {}

    public function query(string $sql): SqlResult
    {
        if ($this->failOn !== null && $sql === $this->failOn) {
            throw new \RuntimeException('Query failed: '.$sql);
        }

        $this->queries[] = $sql;

        return new FakeRowResult([]);
    }

    public function prepare(string $sql): SqlStatement
    {
        throw new \LogicException('Not implemented');
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        throw new \LogicException('Not implemented');
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new \LogicException('Not implemented');
    }

    public function getConfig(): SqlConfig
    {
        return $this->config;
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return SqlTransactionIsolationLevel::Committed;
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void {}

    public function getLastUsedAt(): int
    {
        return time();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void {}
}
