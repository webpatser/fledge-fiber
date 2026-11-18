<?php

namespace Tests\Fledge\database\Stubs;

use Fledge\Async\Cancellation;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresConnection;
use Fledge\Async\Database\Postgres\PostgresListener;
use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\Postgres\PostgresStatement;
use Fledge\Async\Database\Postgres\PostgresTransaction;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\Database\SqlTransactionIsolation;

use function Fledge\Async\Database\Postgres\postgresConnector;

/**
 * Connects through the real Postgres connector and records every SQL string the pool
 * sends on a connection object itself (query, execute, prepare), so a test can count
 * the DISCARD ALL round trips of the pool's checkouts.
 */
class RecordingPostgresConnector implements SqlConnector
{
    /** @var list<string> */
    public array $log = [];

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): PostgresConnection
    {
        $connection = postgresConnector()->connect($config, $cancellation);
        \assert($connection instanceof PostgresConnection);

        return new RecordingPostgresConnection($connection, $this->log);
    }

    public function count(string $sql): int
    {
        return \count(\array_filter($this->log, static fn (string $logged): bool => $logged === $sql));
    }
}

/**
 * Delegates everything to the real connection, logging the SQL of query/execute/prepare.
 */
class RecordingPostgresConnection implements PostgresConnection
{
    /**
     * @param  list<string>  $log
     */
    public function __construct(private readonly PostgresConnection $inner, private array &$log) {}

    public function query(string $sql): PostgresResult
    {
        $this->log[] = $sql;

        return $this->inner->query($sql);
    }

    public function prepare(string $sql): PostgresStatement
    {
        $this->log[] = $sql;

        return $this->inner->prepare($sql);
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        $this->log[] = $sql;

        return $this->inner->execute($sql, $params);
    }

    public function beginTransaction(): PostgresTransaction
    {
        return $this->inner->beginTransaction();
    }

    public function listen(string $channel): PostgresListener
    {
        return $this->inner->listen($channel);
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        return $this->inner->notify($channel, $payload);
    }

    public function quoteLiteral(string $data): string
    {
        return $this->inner->quoteLiteral($data);
    }

    public function quoteIdentifier(string $name): string
    {
        return $this->inner->quoteIdentifier($name);
    }

    public function escapeByteA(string $data): string
    {
        return $this->inner->escapeByteA($data);
    }

    public function getConfig(): PostgresConfig
    {
        return $this->inner->getConfig();
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return $this->inner->getTransactionIsolation();
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        $this->inner->setTransactionIsolation($isolation);
    }

    public function getLastUsedAt(): int
    {
        return $this->inner->getLastUsedAt();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->inner->onClose($onClose);
    }
}
