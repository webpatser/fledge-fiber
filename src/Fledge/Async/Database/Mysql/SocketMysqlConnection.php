<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Stream\SocketConnector;
use Fledge\Async\Stream\SocketException;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Database\SqlTransactionIsolation;
use Fledge\Async\Database\SqlTransactionIsolationLevel;
use Fledge\Loop;

final class SocketMysqlConnection implements MysqlConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private SqlTransactionIsolation $transactionIsolation = SqlTransactionIsolationLevel::Committed;

    /**
     * Fledge: whether a level was passed to setTransactionIsolation(). Until then
     * beginTransaction() sends a plain START TRANSACTION, as pdo_mysql does, so the
     * session's own level (server default or a SET SESSION at connect) applies.
     */
    private bool $isolationSet = false;

    private ?DeferredFuture $busy = null;

    /** @var \Closure():void Function used to release connection after a transaction has completed. */
    private readonly \Closure $release;

    public static function connect(
        SocketConnector $connector,
        MysqlConfig $config,
        ?Cancellation $cancellation = null,
    ): self {
        $uri = $config->getConnectionString();
        $context = $config->getConnectContext();

        // MySQL is strictly request/response: without TCP_NODELAY, Nagle plus delayed ACK
        // stalls each small command packet (prepare, execute, close) by ~20 ms over TCP.
        if (\str_starts_with($uri, 'tcp://')) {
            $context = $context->withTcpNoDelay();
        }

        try {
            $socket = $connector->connect($uri, $context, $cancellation);
        } catch (SocketException $exception) {
            throw new SqlException(
                'Connecting to the MySQL server failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $processor = new Internal\ConnectionProcessor($socket, $config);
        $processor->connect($cancellation);
        return new self($processor);
    }

    /** Server error for a command it does not know (ER_UNKNOWN_COM_ERROR). */
    private const ER_UNKNOWN_COM_ERROR = 1047;

    /** The database the connection was opened with, selected again by resetSession(). */
    private readonly ?string $database;

    private int $resetRoundTrips = 0;

    private function __construct(private readonly Internal\ConnectionProcessor $processor)
    {
        $this->database = $processor->getConfig()->getDatabase();

        $busy = &$this->busy;
        $this->release = static function () use (&$busy): void {
            $busy?->complete();
            $busy = null;
        };
    }

    public function getConfig(): MysqlConfig
    {
        return $this->processor->getConfig();
    }

    /**
     * Whether the server reported NO_BACKSLASH_ESCAPES for this session in its last status flags.
     */
    public function isBackslashEscapingDisabled(): bool
    {
        return $this->processor->isBackslashEscapingDisabled();
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return $this->transactionIsolation;
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        $this->transactionIsolation = $isolation;
        $this->isolationSet = true;
    }

    /**
     * @return bool False if the connection has been closed.
     */
    public function isClosed(): bool
    {
        return $this->processor->isClosed();
    }

    /**
     * @return int Timestamp of the last time this connection was used.
     */
    public function getLastUsedAt(): int
    {
        return $this->processor->getLastUsedAt();
    }

    public function useCharacterSet(string $charset, string $collate): void
    {
        $this->processor->useCharacterSet($charset, $collate)->await();
    }

    public function close(): void
    {
        // Send close command if connection is not already in a closed or closing state
        if (!$this->processor->isClosed()) {
            $this->processor->sendClose()->await();
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->processor->onClose($onClose);
    }

    public function useDatabase(string $database): void
    {
        $this->processor->useDatabase($database)->await();
    }

    public function query(string $sql): MysqlResult
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        return $this->processor->query($sql)->await();
    }

    public function beginTransaction(): MysqlTransaction
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        $this->busy = $deferred = new DeferredFuture();

        $sql = !$this->isolationSet ? "START TRANSACTION" : \sprintf(
            "SET SESSION TRANSACTION ISOLATION LEVEL %s; START TRANSACTION",
            $this->transactionIsolation->toSql(),
        );

        try {
            $this->processor->query($sql)->await();
        } catch (\Throwable $exception) {
            $this->busy = null;
            $deferred->complete();
            throw $exception;
        }

        $executor = new Internal\MysqlNestableExecutor($this->processor);
        return new Internal\MysqlConnectionTransaction($executor, $this->release, $this->transactionIsolation);
    }

    public function ping(): void
    {
        $this->processor->ping()->await();
    }

    /**
     * Round trips resetSession() has spent on this connection.
     */
    public function getResetRoundTrips(): int
    {
        return $this->resetRoundTrips;
    }

    /**
     * Reset the session to its state right after connect: COM_RESET_CONNECTION (user variables,
     * temporary tables, locks, prepared statements and session variables are gone; the current
     * database is kept), then the session settings connect() applied (SET NAMES, sql_mode),
     * $statements and USE of the database the connection was opened with, in one query.
     *
     * Two round trips. The two commands are not pipelined: the processor runs one command at a
     * time (one parse callback and one packet sequence per command), so a second packet written
     * before the first reply would be parsed by the first command's handler.
     *
     * @param list<string> $statements
     *
     * @return bool False when the server does not know COM_RESET_CONNECTION (error 1047, MySQL
     *              before 5.7.3, MariaDB before 10.2.4, some proxies): the session is unchanged.
     *
     * @throws \Throwable When the connection fails, the server refuses the reset for another
     *                    reason, or the session settings fail after the reset.
     */
    public function resetSession(array $statements = []): bool
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        try {
            $this->resetRoundTrips++;
            $this->processor->resetConnection()->await();
        } catch (\Throwable $exception) {
            if (!$this->processor->isClosed()
                && $exception instanceof SqlQueryError
                && $exception->getErrorCode() === self::ER_UNKNOWN_COM_ERROR
            ) {
                return false;
            }

            throw $exception;
        }

        $config = $this->processor->getConfig();
        $init = [];

        if ($config->getCharset() !== '') {
            $collate = $config->getCollation();
            $init[] = "SET NAMES '{$config->getCharset()}'" . ($collate === '' ? '' : " COLLATE '$collate'");
        }

        if ($config->getSqlMode() !== null) {
            $init[] = "SET SESSION sql_mode='{$config->getSqlMode()}'";
        }

        $init = [...$init, ...$statements];

        // COM_RESET_CONNECTION keeps the current database, which a USE on this session changed.
        if ($this->database !== null && $this->database !== '') {
            $init[] = 'USE `' . \str_replace('`', '``', $this->database) . '`';
        }

        if ($init === []) {
            return true;
        }

        $this->resetRoundTrips++;

        for ($result = $this->processor->query(\implode('; ', $init))->await();$result !== null; $result = $result->getNextResult()) {
            foreach ($result as $_) {
                // discard
            }
        }

        return true;
    }

    public function prepare(string $sql): MysqlStatement
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        return $this->processor->prepare($sql)->await();
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        $statement = $this->prepare($sql);
        return $statement->execute($params);
    }

    public function __destruct()
    {
        $processor = $this->processor;
        Loop::queue(static fn () => $processor->unreference());
    }
}
