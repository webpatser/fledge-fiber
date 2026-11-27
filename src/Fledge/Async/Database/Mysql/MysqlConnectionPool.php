<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Database\SqlCommonConnectionPool;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\Database\SqlResult;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Loop\Durations;
use Time\Duration;

/**
 * @extends SqlCommonConnectionPool<MysqlConfig, MysqlResult, MysqlStatement, MysqlTransaction, MysqlConnection>
 */
final class MysqlConnectionPool extends SqlCommonConnectionPool implements MysqlConnection
{
    /** @var \WeakMap<SqlConnection, true> Every connection handed out by pop(), to read its session state. */
    private \WeakMap $seen;

    /**
     * @param positive-int $maxConnections
     * @param Duration|positive-int $idleTimeout Time until idle connections are removed from the pool, as a `Duration`
     *     or in seconds. A `Duration` is rounded up to whole seconds.
     * @param SqlConnector<MysqlConfig, MysqlConnection>|null $connector
     * @param Duration|float|null $waitTimeout Fledge: time a caller may wait for a connection, as a `Duration` or in
     *     seconds, null for no limit
     */
    public function __construct(
        MysqlConfig $config,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
        Duration|int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        ?SqlConnector $connector = null,
        Duration|float|null $waitTimeout = null,
    ) {
        $idleTimeout = $idleTimeout instanceof Duration
            ? (int) \ceil(Durations::toSeconds($idleTimeout))
            : $idleTimeout;
        $waitTimeout = Durations::nullable($waitTimeout);

        $this->seen = new \WeakMap();

        parent::__construct(
            $config,
            $connector ?? mysqlConnector(),
            $maxConnections,
            $idleTimeout,
            waitTimeout: $waitTimeout,
        );
    }

    protected function pop(): SqlConnection
    {
        $connection = parent::pop();
        $this->seen[$connection] = true;

        return $connection;
    }

    /**
     * Whether the server reports NO_BACKSLASH_ESCAPES for the pool's sessions, as mysqlnd reads it from the
     * last status flags before escaping: true when every open connection reports it, false when none does,
     * null when they disagree or the state cannot be read. Opens a connection when none is open yet, as a
     * pdo_mysql handle is connected before quote() runs.
     */
    public function isBackslashEscapingDisabled(): ?bool
    {
        $states = $this->backslashEscapingStates();

        if ($states === []) {
            $this->push($this->pop());
            $states = $this->backslashEscapingStates();
        }

        if (\in_array(null, $states, true)) {
            return null;
        }

        if (\in_array(true, $states, true)) {
            return \in_array(false, $states, true) ? null : true;
        }

        return \in_array(false, $states, true) ? false : null;
    }

    /**
     * @return list<bool|null>
     */
    private function backslashEscapingStates(): array
    {
        $states = [];

        foreach ($this->seen as $connection => $_) {
            if ($connection->isClosed()) {
                continue;
            }

            $states[] = $connection instanceof SocketMysqlConnection ? $connection->isBackslashEscapingDisabled() : null;
        }

        return $states;
    }

    protected function createResult(SqlResult $result, \Closure $release): MysqlResult
    {
        \assert($result instanceof MysqlResult);
        return new Internal\MysqlPooledResult($result, $release);
    }

    protected function createStatement(SqlStatement $statement, \Closure $release): MysqlStatement
    {
        \assert($statement instanceof MysqlStatement);
        return new Internal\MysqlPooledStatement($statement, $release);
    }

    protected function createStatementPool(string $sql, \Closure $prepare): MysqlStatement
    {
        return new Internal\MysqlStatementPool($this, $sql, $prepare);
    }

    protected function createTransaction(SqlTransaction $transaction, \Closure $release): MysqlTransaction
    {
        return new Internal\MysqlPooledTransaction($transaction, $release);
    }

    /**
     * Changes return type to this library's configuration type.
     */
    public function getConfig(): MysqlConfig
    {
        return parent::getConfig();
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function query(string $sql): MysqlResult
    {
        return parent::query($sql);
    }

    /**
     * Changes return type to this library's Statement type.
     */
    public function prepare(string $sql): MysqlStatement
    {
        return parent::prepare($sql);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function execute(string $sql, array $params = []): MysqlResult
    {
        return parent::execute($sql, $params);
    }

    /**
     * Changes return type to this library's Transaction type.
     */
    public function beginTransaction(): MysqlTransaction
    {
        return parent::beginTransaction();
    }
}
