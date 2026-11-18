<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlCommonConnectionPool;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlTransaction;
use PDO;
use Revolt\EventLoop\FiberLocal;

/**
 * Abstract PDO-compatible shim wrapping an Fledge Async SQL connection pool.
 *
 * Implements the subset of PDO methods used by Illuminate\Database\Connection:
 * prepare, exec, lastInsertId, beginTransaction, commit, rollBack,
 * inTransaction, getAttribute, quote.
 *
 * Transaction pinning: Fledge Async pools dispatch queries to different connections.
 * beginTransaction() obtains a pinned SqlTransaction so all subsequent
 * queries within the transaction hit the same server connection.
 *
 * Session pinning (drivers where pinsSessions() is true): pdo_mysql is one
 * connection per PDO, so SET, temporary tables, GET_LOCK, user variables and
 * LAST_INSERT_ID() carry over from one statement to the next. To match that,
 * each fiber (and the main context) gets one pooled connection on its first
 * statement (see SessionPin); its transactions run on that connection too.
 * Release rule: a pin goes back to the pool when its fiber's scope ends, or
 * earlier, as soon as another caller waits for a connection, if its owner is
 * between statements (not between a prepare() and its execute(), and not right
 * after DML), outside a transaction, has no unread rows and has run only
 * statements leavesSessionUnchanged() proves clean. A pin whose session
 * may have changed stays until the scope ends and is reset on release.
 * Release is lazy (only when someone waits), not after every statement: a
 * clean pin costs nothing to keep, and a fiber that is not contended stays on
 * one connection, so FOUND_ROWS(), ROW_COUNT(), SHOW WARNINGS and CONNECTION_ID()
 * in a following statement read the same session, as with pdo_mysql.
 * Results on a pinned connection are buffered unless the PDO was built with
 * Pdo\Mysql::ATTR_USE_BUFFERED_QUERY false, as pdo_mysql buffers by default, so a
 * query inside a cursor() loop does not wait behind unread rows; unbuffered, a
 * second query while rows are unread fails with error 2014, as in pdo_mysql.
 *
 * Errors: every public method runs through guard(), which turns anything the
 * async driver raises into the PDOException pdo_mysql/pdo_pgsql would throw, so
 * Laravel's unique-constraint, concurrency and lost-connection detection work.
 */
abstract class FledgePdo
{
    /**
     * The Fledge Async connection pool.
     */
    protected SqlConnectionPool $pool;

    /**
     * The active transaction (pinned to a single connection), if any.
     */
    protected ?SqlTransaction $transaction = null;

    /**
     * The fiber that began the pinned transaction (null: the main context).
     * Only meaningful while $transaction is set.
     */
    protected ?\Fiber $transactionFiber = null;

    /**
     * The last insert ID of the current fiber (or the main context, which uses the backing
     * slot). Per fiber scope, not per connection: pdo_mysql's id belongs to the PDO's one
     * connection, so here it follows the fiber, also when its pin was handed over.
     */
    protected string|false $lastInsertId = false {
        get {
            if (\Fiber::getCurrent() === null) {
                return $this->lastInsertId;
            }

            return $this->fiberLastInsertIds?->get() ?? false;
        }
        set {
            if (\Fiber::getCurrent() === null) {
                $this->lastInsertId = $value;

                return;
            }

            $this->fiberLastInsertIds ??= new FiberLocal(static fn (): string|false => false);
            $this->fiberLastInsertIds->set($value);
        }
    }

    /**
     * @var FiberLocal<string|false>|null
     */
    private ?FiberLocal $fiberLastInsertIds = null;

    /**
     * Cached server version string.
     */
    protected ?string $serverVersion = null;

    /**
     * The connection pinned to each fiber (and the main context).
     *
     * @var FiberLocal<SessionPin|null>|null
     */
    private ?FiberLocal $sessionPins = null;

    /**
     * Statements prepared in the open transaction on a pinned connection.
     *
     * @var \WeakMap<FledgePdoStatement, true>|null
     */
    private ?\WeakMap $transactionStatements = null;

    /**
     * The main context's pin (fibers keep theirs in $sessionPins).
     */
    private ?SessionPin $mainPin = null;

    /**
     * Nesting depth of shim operations in the main context.
     */
    private int $mainDepth = 0;

    /**
     * Nesting depth of shim operations per fiber.
     *
     * @var \WeakMap<\Fiber, int>|null
     */
    private ?\WeakMap $depths = null;

    /**
     * Every live pin, so a waiting caller can take back a releasable one.
     *
     * @var \WeakMap<SessionPin, true>|null
     */
    private ?\WeakMap $pins = null;

    /**
     * Whether results on a pinned connection are read fully at execute() (pdo_mysql's
     * Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true by default).
     */
    protected bool $bufferedQueries = true;

    /**
     * Statements prepared on the server so far, re-prepares included.
     */
    private int $serverPrepares = 0;

    /**
     * Create a new Fledge Async PDO shim.
     */
    public function __construct(SqlConnectionPool $pool)
    {
        $this->pool = $pool;

        if ($this->pinsSessions() && $pool instanceof SqlCommonConnectionPool) {
            // Weak, so the pool (which the PDO owns) does not keep the PDO alive.
            $self = \WeakReference::create($this);
            $pool->setReclaimer(static fn (): bool => $self->get()?->reclaimIdlePin() ?? false);
        }
    }

    /**
     * Prepare a statement for execution.
     */
    public function prepare(string $query, array $options = []): FledgePdoStatement
    {
        $this->assertOwnsTransaction();

        return $this->guard(function () use ($query): FledgePdoStatement {
            $this->assertNoUnreadResult();
            $link = $this->link();
            $pinned = $this->isPinned($link);
            $this->noteStatement($query);
            $this->serverPrepares++;

            $statement = new FledgePdoStatement(
                $link->prepare($query),
                pdo: $this,
                driver: $this->getDriverName(),
                transaction: $this->transaction,
                buffered: $pinned && $this->bufferedQueries,
                sql: $pinned ? $query : null,
            );

            if ($pinned) {
                $this->trackPinnedStatement($statement, $link);

                // Not releasable until this statement ran: a hand-over between prepare() and
                // execute() would prepare it again (and wait for a connection twice).
                $pin = $this->currentPin();

                if ($pin !== null) {
                    $pin->pending[$statement] = true;
                }
            }

            return $statement;
        });
    }

    /**
     * Prepare a detached statement again on the current fiber's connection (or its open
     * transaction), as pdo_mysql keeps a statement usable across transactions.
     *
     * @internal
     */
    public function reattach(FledgePdoStatement $statement, string $query): void
    {
        $this->guard(function () use ($statement, $query): void {
            $this->assertNoUnreadResult();
            $link = $this->link();
            $this->noteStatement($query);
            $this->serverPrepares++;

            $statement->attach($link->prepare($query), $link === $this->transaction ? $this->transaction : null);

            if ($this->isPinned($link)) {
                $this->trackPinnedStatement($statement, $link);
            }
        });
    }

    /**
     * Statements this PDO prepared on the server so far, re-prepares of detached statements
     * included (for tests and diagnostics).
     *
     * @internal
     */
    public function serverPrepareCount(): int
    {
        return $this->serverPrepares;
    }

    /**
     * $statement is about to run on the current context's connection: it no longer keeps the pin
     * as prepared-but-not-executed, and DML keeps the pin for the owner's next statement.
     *
     * @internal
     */
    public function statementExecuting(FledgePdoStatement $statement, ?string $sql): void
    {
        $this->guard(function () use ($statement, $sql): void {
            $pin = $this->currentPin();

            if ($pin !== null && isset($pin->statements[$statement])) {
                unset($pin->pending[$statement]);
            } else {
                // Prepared in another fiber (rare): that fiber's pin must not wait for it.
                foreach ($this->pins ?? [] as $other => $_) {
                    unset($other->pending[$statement]);
                }
            }

            if ($pin !== null && $sql !== null) {
                $pin->holdForNext = self::isDml($sql);
            }
        });
    }

    /**
     * Remember a statement prepared on the fiber's pinned connection, so it is detached
     * when its transaction ends or the pin is released.
     */
    private function trackPinnedStatement(FledgePdoStatement $statement, SqlTransaction|SqlConnection $link): void
    {
        $pin = $this->currentPin();

        if ($pin !== null) {
            $pin->statements[$statement] = true;
        }

        if ($link === $this->transaction) {
            $this->transactionStatements ??= new \WeakMap;
            $this->transactionStatements[$statement] = true;
        }
    }

    /**
     * Execute an SQL statement and return the number of affected rows.
     */
    public function exec(string $statement): int|false
    {
        $this->assertOwnsTransaction();

        return $this->guard(function () use ($statement): int {
            $this->assertNoUnreadResult();
            $link = $this->link();
            $this->noteStatement($statement);

            if (($pin = $this->currentPin()) !== null) {
                $pin->holdForNext = self::isDml($statement);
            }

            $result = $link->query($statement);

            $this->trackLastInsertId($result);

            // Read any rows to the end, as pdo_mysql does: unread rows would hold
            // the pinned connection for this fiber's next statement.
            if ($this->isPinned($link)) {
                for ($next = $result; $next !== null; $next = $next->getNextResult()) {
                    foreach ($next as $_) {
                        // discard
                    }
                }
            }

            return $result->getRowCount() ?? 0;
        });
    }

    /**
     * Begin a transaction.
     *
     * Obtains a pinned connection from the pool so all subsequent
     * queries within the transaction use the same server connection.
     */
    public function beginTransaction(): bool
    {
        $this->assertOwnsTransaction();

        return $this->guard(function (): bool {
            if ($this->transaction !== null) {
                throw FledgePdoException::pdoError('There is already an active transaction');
            }

            $pin = $this->sessionPin();

            if ($pin === null) {
                $this->transaction = $this->pool->beginTransaction();
            } else {
                // The fiber's own connection, so the transaction sees its session state
                // (and a pool of one cannot deadlock against the fiber's own pin). No level
                // is passed: like pdo_mysql, a plain START TRANSACTION at the session's level
                // (the server default, or the connector's isolation_level).
                $this->transaction = $pin->connection->beginTransaction();
                $pin->inTransaction = true;
            }

            $this->transactionFiber = \Fiber::getCurrent();

            return true;
        });
    }

    /**
     * Commit the current transaction.
     *
     * The driver deactivates the transaction even when COMMIT fails (the server
     * rolls back on a deadlock or a lost connection), so the pin is always released.
     */
    public function commit(): bool
    {
        $this->assertOwnsTransaction();

        return $this->guard(function (): bool {
            $transaction = $this->transaction ?? throw FledgePdoException::pdoError('There is no active transaction');

            try {
                $transaction->commit();
            } finally {
                $this->endTransaction();
            }

            return true;
        });
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): bool
    {
        $this->assertOwnsTransaction();

        return $this->guard(function (): bool {
            $transaction = $this->transaction ?? throw FledgePdoException::pdoError('There is no active transaction');

            try {
                $transaction->rollback();
            } finally {
                $this->endTransaction();
            }

            return true;
        });
    }

    /**
     * Whether the current fiber (or the main context) may use the pinned transaction.
     * True when no transaction is pinned.
     */
    public function ownsTransaction(): bool
    {
        return $this->transaction === null || $this->transactionFiber === \Fiber::getCurrent();
    }

    /**
     * Refuse to let another fiber run inside the transaction a fiber has pinned.
     *
     * The pin is per connection, not per fiber, so without this check a query from a
     * second fiber would silently run inside (and be committed or rolled back with) the
     * first fiber's transaction. Called outside guard() so the LogicException is not
     * mapped to a PDOException (which Laravel could mistake for a lost connection).
     *
     * @param  SqlTransaction|null  $transaction  Only check when this is the pinned transaction
     *                                            (used by statements prepared inside one).
     *
     * @throws \LogicException
     */
    public function assertOwnsTransaction(?SqlTransaction $transaction = null): void
    {
        if ($transaction !== null && $transaction !== $this->transaction) {
            return;
        }

        if ($this->ownsTransaction()) {
            return;
        }

        throw new \LogicException(
            'Another fiber holds an open transaction on this database connection. '
            .'Queries from other fibers would run inside it, so they are refused until it is committed or rolled back. '
            .'Use a separate database connection per concurrent fiber, or the fledge-mysql-native / fledge-mariadb-native driver, which keeps transactions per fiber.'
        );
    }

    /**
     * Check if inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->guard(fn (): bool => $this->transaction !== null);
    }

    /**
     * Returns the ID of the last inserted row.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->guard(fn (): string|false => $this->lastInsertId);
    }

    /**
     * Retrieve a database connection attribute.
     */
    public function getAttribute(int $attribute): mixed
    {
        return $this->guard(fn (): mixed => match (true) {
            $attribute === PDO::ATTR_SERVER_VERSION => $this->getServerVersion(),
            $attribute === PDO::ATTR_DRIVER_NAME => $this->getDriverName(),
            // Pdo\Pgsql attributes share the integer range, so only for MySQL.
            $attribute === \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY && $this->getDriverName() === 'mysql' => $this->bufferedQueries,
            default => null,
        });
    }

    /**
     * Run a shim operation, translating anything the async driver raises into the
     * PDOException pdo_mysql/pdo_pgsql would throw. Every public method goes through here.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    public function guard(\Closure $operation): mixed
    {
        $fiber = \Fiber::getCurrent();

        if ($this->enterOperation($fiber) === 1 && ($pin = $this->currentPin()) !== null) {
            $pin->busy = true;
        }

        try {
            return $operation();
        } catch (\Throwable $e) {
            throw $this->toPdoException($e);
        } finally {
            if ($this->leaveOperation($fiber) === 0) {
                $this->settlePin();
            }
        }
    }

    private function enterOperation(?\Fiber $fiber): int
    {
        if ($fiber === null) {
            return ++$this->mainDepth;
        }

        $this->depths ??= new \WeakMap;

        return $this->depths[$fiber] = ($this->depths[$fiber] ?? 0) + 1;
    }

    private function leaveOperation(?\Fiber $fiber): int
    {
        if ($fiber === null) {
            return --$this->mainDepth;
        }

        $depth = ($this->depths[$fiber] ?? 1) - 1;

        if ($depth > 0) {
            $this->depths[$fiber] = $depth;
        } else {
            unset($this->depths[$fiber]);
        }

        return $depth;
    }

    /**
     * The current context is between statements: hand its pin back right away if another
     * caller is waiting for a connection and the pin is releasable (see the class doc).
     */
    private function settlePin(): void
    {
        $pin = $this->currentPin();

        if ($pin === null || $pin->isReleased()) {
            return;
        }

        $pin->busy = false;

        if ($pin->isReleasable() && $pin->pool->isAwaitingConnection()) {
            $this->storePin(\Fiber::getCurrent() === null, null);
            unset($this->pins[$pin]);
            $pin->release();
        }
    }

    /**
     * The pool's reclaimer: a caller is about to wait for a connection, so release one pin
     * whose owner is between statements with a clean session. Never suspends. The owner
     * notices on its next statement and leases again.
     */
    private function reclaimIdlePin(): bool
    {
        foreach ($this->pins ?? [] as $pin => $_) {
            if ($pin->isReleasable()) {
                unset($this->pins[$pin]);
                $pin->release();

                return true;
            }
        }

        return false;
    }

    /**
     * Refuse a statement on the current context's connection while an unbuffered result on it
     * has unread rows, with the error pdo_mysql raises (instead of waiting behind those rows).
     *
     * @internal
     */
    public function assertNoUnreadResult(): void
    {
        $this->guard(function (): void {
            $pin = $this->currentPin();

            if ($pin === null || $pin->isReleased() || \count($pin->unread) === 0) {
                return;
            }

            $message = 'Cannot execute queries while other unbuffered queries are active.  Consider using PDOStatement::fetchAll().  '
                .'Alternatively, if your code is only ever going to run against mysql, you may enable query buffering by setting the PDO::MYSQL_ATTR_USE_BUFFERED_QUERY attribute.';

            throw new FledgePdoException('SQLSTATE[HY000]: General error: 2014 '.$message, 'HY000', ['HY000', 2014, $message]);
        });
    }

    /**
     * Record that $statement has unread rows on the current context's pin, which keeps the pin.
     *
     * @internal
     *
     * @return \WeakReference<SessionPin>|null
     */
    public function openCursor(FledgePdoStatement $statement): ?\WeakReference
    {
        return $this->guard(function () use ($statement): ?\WeakReference {
            $pin = $this->currentPin();

            if ($pin === null || $pin->isReleased()) {
                return null;
            }

            $pin->unread[$statement] = true;

            return \WeakReference::create($pin);
        });
    }

    /**
     * Translate a driver throwable into the PDOException pdo_mysql/pdo_pgsql would throw.
     * Programming errors (TypeError and the like) come back untouched.
     */
    public function toPdoException(\Throwable $error): \Throwable
    {
        return FledgePdoException::fromThrowable($error, $this->getDriverName());
    }

    /**
     * Get the server version string, querying on first call.
     */
    protected function getServerVersion(): string
    {
        if ($this->serverVersion === null) {
            $rows = iterator_to_array($this->link()->query($this->getVersionQuery()), false);
            $row = $rows[0] ?? null;
            $this->serverVersion = $row ? (string) reset($row) : 'unknown';
        }

        return $this->serverVersion;
    }

    /**
     * Whether each fiber keeps one pooled connection for all its statements (see the class doc).
     */
    protected function pinsSessions(): bool
    {
        return false;
    }

    /**
     * Resets a pinned connection's session before it goes back to the pool: true when reset,
     * false to close the connection instead. Must not capture $this (pins outlive scopes).
     * Null: the connection goes back unchanged.
     *
     * @return (\Closure(SqlConnection): bool)|null
     */
    protected function sessionReset(): ?\Closure
    {
        return null;
    }

    /**
     * Whether $link is the current fiber's pinned connection or a transaction on it.
     */
    private function isPinned(SqlTransaction|SqlConnection $link): bool
    {
        return $link !== $this->pool && $this->pinsSessions() && $this->pool instanceof SqlCommonConnectionPool;
    }

    /**
     * Where the current fiber's statements go: its own open transaction, else its pinned
     * connection, else the pool.
     */
    protected function link(): SqlTransaction|SqlConnection
    {
        if ($this->transaction !== null && $this->ownsTransaction()) {
            return $this->transaction;
        }

        return $this->sessionPin()?->connection ?? $this->pool;
    }

    /**
     * The connection pinned to the current fiber (or the main context), leasing one from the
     * pool on first use. Waits like any pool caller when every connection is taken. Null when
     * this driver does not pin sessions.
     */
    protected function sessionPin(): ?SessionPin
    {
        if (! $this->pinsSessions() || ! $this->pool instanceof SqlCommonConnectionPool) {
            return null;
        }

        $main = \Fiber::getCurrent() === null;
        $pin = $main ? $this->mainPin : ($this->sessionPins ?? null)?->get();

        if ($pin !== null && ! $pin->isReleased() && ! $pin->connection->isClosed()) {
            return $pin;
        }

        // A lost connection goes back to the pool (which discards it) before a new one is leased;
        // a pin a waiting caller took back (see reclaimIdlePin()) is already released.
        $this->storePin($main, null);

        if ($pin !== null) {
            unset($this->pins[$pin]);
            $pin->release();
        }

        unset($pin);

        $pin = new SessionPin($this->pool, $this->pool->leaseConnection(), $this->sessionReset());
        $this->pins ??= new \WeakMap;
        $this->pins[$pin] = true;
        $this->storePin($main, $pin);

        return $pin;
    }

    private function storePin(bool $main, ?SessionPin $pin): void
    {
        if ($main) {
            $this->mainPin = $pin;

            return;
        }

        $this->sessionPins ??= new FiberLocal(static fn (): ?SessionPin => null);
        $this->sessionPins->set($pin);
    }

    /**
     * The current fiber's (or main context's) pin, without leasing one.
     */
    private function currentPin(): ?SessionPin
    {
        return \Fiber::getCurrent() === null ? $this->mainPin : ($this->sessionPins ?? null)?->get();
    }

    /**
     * Record that the current context ran $query on its pin: a statement that is not provably
     * clean keeps the pin until the scope ends and has it reset on release.
     */
    private function noteStatement(string $query): void
    {
        $pin = $this->currentPin();

        if ($pin === null || $pin->sessionChanged) {
            return;
        }

        if (! self::leavesSessionUnchanged($query)) {
            $pin->sessionChanged = true;
        }
    }

    /**
     * Fails safe: true only for one plain SELECT / SHOW / EXPLAIN / DESCRIBE / WITH, or one
     * INSERT / UPDATE / DELETE / REPLACE, with no user variable, no SELECT ... INTO, no lock
     * function, no LAST_INSERT_ID(), no SQL_CALC_FOUND_ROWS, no temporary table, no executable
     * comment (MySQL runs the body of a /*! comment, MariaDB that of /*M!), no unterminated
     * comment, no second statement after a semicolon, and no call except the built-ins in
     * BUILTIN_CALLS (a schema-qualified or quoted name counts as a stored function). Plain
     * comments, optimizer hints (/*+ ... *\/) and "-- " or "#" line comments are skipped.
     * Anything else counts as changing the session.
     *
     * DML counts as clean: PDO::lastInsertId() does not depend on the connection (the shim takes
     * the id from each result and keeps it per fiber), and after DML the pin is kept for the
     * owner's next statement (SessionPin::$holdForNext), so a following SELECT LAST_INSERT_ID(),
     * ROW_COUNT(), FOUND_ROWS() or SHOW WARNINGS reads the same session. An open transaction
     * keeps the pin anyway, whatever ran in it.
     *
     * A backslash anywhere is dirty: whether it escapes depends on NO_BACKSLASH_ESCAPES (strings)
     * and ANSI_QUOTES (double quotes as identifiers), which the text does not show. Without a
     * backslash a double-quoted token has the same extent read as a string or as an identifier;
     * the identifier reading can only name a stored function before "(" or ".", which is dirty.
     * "--" followed by a control character is dirty (the server starts a comment there). Every
     * name before "(", ASCII or not, must be in BUILTIN_CALLS, which lists only names that are
     * built-in on both MySQL 8 and MariaDB 11. A PCRE error anywhere is dirty.
     *
     * Residual risk: triggers, views and stored code are invisible to text classification. A
     * SELECT from a view whose definition calls a function that sets a variable, or DML that
     * fires a trigger taking GET_LOCK, looks clean here, so such a session change can go to
     * the next fiber with the connection.
     */
    public static function leavesSessionUnchanged(string $query): bool
    {
        if (str_contains($query, '\\')) {
            return false;
        }

        $sql = self::stripLiteralsAndComments($query);

        if ($sql === null) {
            return false;
        }

        // One trailing semicolon is the end of the statement; anything after one is a second statement.
        // A /* left over is an unterminated comment. A "--" not followed by whitespace or a control
        // character is two minus signs; followed by a control character the server reads a comment.
        $sql = rtrim(trim($sql), "; \t\r\n");

        if (preg_match('/;|\/\*|--[\x00-\x08\x0e-\x1f\x7f]/', $sql) !== 0) {
            return false;
        }

        if (preg_match('/^[\s(]*(select|show|explain|describe|desc|with|insert|update|delete|replace)\b/i', $sql, $verb) !== 1) {
            return false;
        }

        $dml = in_array(strtolower($verb[1]), ['insert', 'update', 'delete', 'replace'], true);

        if (preg_match('/@|\b(get_lock|release_lock|release_all_locks|is_used_lock|is_free_lock|last_insert_id|sql_calc_found_rows|temporary)\b/i', $sql) !== 0) {
            return false;
        }

        // SELECT ... INTO @var / OUTFILE / DUMPFILE (INSERT INTO is DML's own INTO).
        if (! $dml && preg_match('/\binto\b/i', $sql) !== 0) {
            return false;
        }

        // The column list of INSERT / REPLACE INTO table (...) is not a call.
        $sql = preg_replace('/\binto\s+[\w$]+(?:\s*\.\s*[\w$]+)?\s*\(/i', 'into (', $sql);

        // The column list of a CTE, WITH [RECURSIVE] t (a, b) AS (...), [, t2 (c) AS (...)], is not a call.
        $sql = $sql === null ? null : preg_replace('/(\bwith(?:\s+recursive)?|,)\s*[a-z_$][\w$]*\s*\(\s*[a-z_$][\w$]*(?:\s*,\s*[a-z_$][\w$]*)*\s*\)\s*as\s*\(/i', '$1 x as (', $sql);

        // CAST(x AS DATETIME(6)) / NCHAR(4): sized type names that are not reserved words (the reserved
        // ones, DECIMAL, CHAR, FLOAT, ..., are in BUILTIN_CALLS, as no stored function can take their name).
        $sql = $sql === null ? null : preg_replace('/\bas\s+(datetime|nchar)\s*\(\s*\d+\s*\)/i', 'as $1', $sql);

        // A PCRE error (backtrack or JIT stack limit) leaves null: fail safe.
        if ($sql === null) {
            return false;
        }

        // db.fn(...), and "fn"(...) or "db".fn(...) under ANSI_QUOTES: a stored function.
        if (preg_match('/\.\s*[\w$\x80-\xff]+\s*\(|\'\'\s*[.(]/i', $sql) !== 0) {
            return false;
        }

        // Any identifier before "(": MySQL names may start with a digit and hold bytes 0x80 and up.
        if (preg_match_all('/([\w$\x80-\xff]+)\s*\(/', $sql, $calls) === false) {
            return false;
        }

        foreach ($calls[1] as $name) {
            if (! isset(self::BUILTIN_CALLS[strtolower($name)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * One left-to-right pass, so a quote inside a comment and a comment marker inside a string are
     * read as the server reads them: quoted literals become '', backtick identifiers " x " (spaced,
     * so a keyword glued to one stays a word), and plain comments a space. The caller has already
     * refused any backslash, so no escape sequence is honoured. Null for an executable comment
     * (/*! or /*M!: the server runs its body) and for a PCRE error. A "--" comment needs whitespace
     * after it here, and line comments end at "\n", as in MySQL's lexer.
     */
    private static function stripLiteralsAndComments(string $query): ?string
    {
        $executable = false;

        $sql = preg_replace_callback(
            '/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\/\*.*?\*\/|(?:--(?=\s|$)|#)[^\n]*/s',
            static function (array $match) use (&$executable): string {
                if (preg_match('/^\/\*M?!/i', $match[0]) !== 0) {
                    $executable = true;
                }

                return match ($match[0][0]) {
                    '\'', '"' => "''",
                    '`' => ' x ',
                    default => ' ',
                };
            },
            $query,
        );

        return $executable ? null : $sql;
    }

    /**
     * Whether $sql starts as INSERT / UPDATE / DELETE / REPLACE.
     */
    private static function isDml(string $sql): bool
    {
        return preg_match('/^[\s(]*(insert|update|delete|replace)\b/i', $sql) === 1;
    }

    /**
     * Keywords and built-in functions that may appear before "(" in a query that leaves the
     * session unchanged. Functions that read per-session state (CONNECTION_ID, DATABASE,
     * FOUND_ROWS, ROW_COUNT) are left out, so a fiber that reads them keeps its connection.
     * Only names built-in on both MySQL 8 and MariaDB 11: a name that is a built-in on one
     * server only (or only at some arities, as POINT() and the geometry constructors on MariaDB)
     * resolves to a stored function or UDF of that name on the other, so it is left out.
     * Checked by calling each name at 0, 1, 2, 3 and 9 arguments on MySQL 8.0 and MariaDB
     * 11.8: none answers "FUNCTION db.name does not exist".
     */
    private const BUILTIN_CALLS = [
        'select' => true, 'in' => true, 'values' => true, 'value' => true, 'exists' => true, 'and' => true, 'or' => true,
        'not' => true, 'as' => true, 'on' => true, 'using' => true, 'from' => true, 'join' => true, 'is' => true,
        'where' => true, 'into' => true, 'row' => true, 'over' => true, 'union' => true, 'all' => true, 'any' => true,
        'with' => true, 'set' => true, 'by' => true, 'having' => true, 'then' => true, 'else' => true, 'when' => true,
        'like' => true, 'between' => true, 'some' => true, 'index' => true, 'key' => true,
        'partition' => true, 'except' => true, 'intersect' => true, 'case' => true, 'binary' => true, 'interval' => true,
        'count' => true, 'sum' => true, 'min' => true, 'max' => true, 'avg' => true, 'coalesce' => true,
        'ifnull' => true, 'if' => true, 'nullif' => true, 'isnull' => true, 'lower' => true, 'upper' => true,
        'lcase' => true, 'ucase' => true, 'concat' => true, 'concat_ws' => true, 'length' => true, 'char_length' => true,
        'substring' => true, 'substr' => true, 'substring_index' => true, 'trim' => true, 'ltrim' => true, 'rtrim' => true,
        'left' => true, 'right' => true, 'lpad' => true, 'rpad' => true, 'locate' => true, 'instr' => true,
        'replace' => true, 'reverse' => true, 'repeat' => true, 'field' => true, 'find_in_set' => true, 'hex' => true,
        'unhex' => true, 'md5' => true, 'sha1' => true, 'sha2' => true, 'crc32' => true, 'uuid' => true,
        'match' => true, 'regexp_replace' => true, 'regexp_substr' => true, 'round' => true, 'floor' => true, 'ceil' => true,
        'ceiling' => true, 'abs' => true, 'mod' => true, 'power' => true, 'pow' => true, 'sqrt' => true, 'rand' => true,
        'sign' => true, 'truncate' => true, 'format' => true, 'cast' => true, 'convert' => true, 'date' => true,
        'time' => true, 'timestamp' => true, 'now' => true, 'curdate' => true, 'curtime' => true, 'current_date' => true,
        'current_timestamp' => true, 'utc_timestamp' => true, 'unix_timestamp' => true, 'from_unixtime' => true,
        'date_format' => true, 'str_to_date' => true, 'date_add' => true, 'date_sub' => true, 'datediff' => true,
        'timestampdiff' => true, 'timestampadd' => true, 'extract' => true, 'year' => true, 'month' => true,
        'day' => true, 'week' => true, 'hour' => true, 'minute' => true, 'second' => true, 'dayofweek' => true,
        'dayofmonth' => true, 'weekday' => true, 'quarter' => true, 'last_day' => true, 'convert_tz' => true,
        'json_extract' => true, 'json_unquote' => true, 'json_contains' => true, 'json_length' => true,
        'json_overlaps' => true, 'json_search' => true, 'json_type' => true, 'json_contains_path' => true,
        'json_object' => true, 'json_array' => true, 'json_arrayagg' => true, 'json_objectagg' => true,
        'json_valid' => true, 'json_keys' => true, 'json_set' => true, 'json_insert' => true, 'json_replace' => true,
        'json_remove' => true, 'json_merge_patch' => true, 'json_value' => true,
        'group_concat' => true, 'distinct' => true, 'row_number' => true, 'rank' => true, 'dense_rank' => true,
        'lag' => true, 'lead' => true, 'first_value' => true, 'last_value' => true, 'greatest' => true, 'least' => true,
        'st_distance_sphere' => true, 'sleep' => true,
        // Sized type names in CAST / CONVERT; all reserved words, CHAR(n) also the built-in.
        'char' => true, 'decimal' => true, 'dec' => true, 'numeric' => true, 'float' => true, 'double' => true,
        'real' => true,
        // Math.
        'radians' => true, 'degrees' => true, 'cos' => true, 'sin' => true, 'tan' => true, 'cot' => true,
        'acos' => true, 'asin' => true, 'atan' => true, 'atan2' => true, 'pi' => true, 'exp' => true, 'ln' => true,
        'log' => true, 'log2' => true, 'log10' => true,
        // Spatial: accessors, predicates and measures, all read-only.
        'mbrcontains' => true, 'mbrdisjoint' => true,
        'mbrequals' => true, 'mbrintersects' => true, 'mbroverlaps' => true, 'mbrtouches' => true, 'mbrwithin' => true,
        'st_area' => true, 'st_asbinary' => true, 'st_asgeojson' => true, 'st_astext' => true, 'st_aswkb' => true,
        'st_aswkt' => true, 'st_buffer' => true, 'st_centroid' => true, 'st_contains' => true, 'st_convexhull' => true,
        'st_crosses' => true, 'st_difference' => true, 'st_dimension' => true, 'st_disjoint' => true,
        'st_distance' => true, 'st_endpoint' => true, 'st_envelope' => true, 'st_equals' => true,
        'st_exteriorring' => true, 'st_geomcollfromtext' => true, 'st_geomfromgeojson' => true,
        'st_geomfromtext' => true, 'st_geomfromwkb' => true, 'st_geometryfromtext' => true,
        'st_geometryfromwkb' => true, 'st_geometryn' => true, 'st_geometrytype' => true, 'st_interiorringn' => true,
        'st_intersection' => true, 'st_intersects' => true, 'st_isclosed' => true, 'st_isempty' => true,
        'st_issimple' => true, 'st_length' => true, 'st_linefromtext' => true,
        'st_numgeometries' => true, 'st_numinteriorrings' => true,
        'st_numpoints' => true, 'st_overlaps' => true, 'st_pointfromtext' => true,
        'st_pointn' => true, 'st_polygonfromtext' => true, 'st_srid' => true,
        'st_startpoint' => true, 'st_symdifference' => true, 'st_touches' => true,
        'st_union' => true, 'st_within' => true, 'st_x' => true, 'st_y' => true,
        // Date and time.
        'dayname' => true, 'monthname' => true, 'to_days' => true, 'from_days' => true, 'to_seconds' => true,
        'utc_date' => true, 'utc_time' => true, 'current_time' => true, 'localtime' => true,
        'localtimestamp' => true, 'sysdate' => true, 'dayofyear' => true, 'weekofyear' => true, 'yearweek' => true,
        'makedate' => true, 'maketime' => true, 'sec_to_time' => true, 'time_to_sec' => true, 'timediff' => true,
        'addtime' => true, 'subtime' => true, 'adddate' => true, 'subdate' => true, 'period_add' => true,
        'period_diff' => true, 'microsecond' => true, 'time_format' => true, 'get_format' => true,
        // JSON (MySQL and MariaDB), all returning a value without side effects.
        'json_quote' => true, 'json_array_append' => true, 'json_array_insert' => true, 'json_merge' => true,
        'json_merge_preserve' => true, 'json_depth' => true, 'json_pretty' => true, 'json_schema_valid' => true,
    ];

    /**
     * Forget the pinned transaction; its connection stays pinned to the fiber.
     */
    private function endTransaction(): void
    {
        $this->transaction = null;
        $this->transactionFiber = null;

        foreach ($this->transactionStatements ?? [] as $statement => $_) {
            $statement->detachFromConnection();
        }

        $this->transactionStatements = null;

        $pin = $this->currentPin();

        if ($pin !== null) {
            $pin->inTransaction = false;
        }
    }

    /**
     * Get the SQL query to retrieve the server version.
     */
    abstract protected function getVersionQuery(): string;

    /**
     * Get the PDO driver name.
     */
    abstract protected function getDriverName(): string;

    /**
     * Quote a string for use in a query.
     */
    abstract public function quote(string $string, int $type = PDO::PARAM_STR): string|false;

    /**
     * Track the last insert ID from a result.
     */
    abstract public function trackLastInsertId(mixed $result): void;

    /**
     * Close the connection pool.
     */
    public function close(): void
    {
        $this->guard(fn () => $this->pool->close());
    }
}
