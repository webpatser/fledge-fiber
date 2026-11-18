<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Fledge\Fiber\Database\Pdo\FledgePdo;
use PDO;
use Pdo\Mysql;
use PDOStatement;

/**
 * Stock pdo_mysql connection with a lease-owner assertion on every call that
 * talks to the server.
 *
 * Only the socket-touching entry points are guarded, plus setAttribute(),
 * whose effect outlives the lease; quote(), lastInsertId(), inTransaction()
 * and attribute reads other than ATTR_SERVER_INFO stay native. Statements
 * come out as NativePdoStatement, which guards execute().
 *
 * A raw PDO that is handed out (getPdo()) and never used holds its
 * connection until the fiber's outermost scope ends, or until the callback
 * ends (main context: DB::disconnect()) if its session changed.
 *
 * Session changes are judged here, where SQL reaches the connection, not
 * where a PDO is handed out: prepare(), exec() and query() mark the lease's
 * session changed unless leavesSessionUnchanged() proves their SQL clean
 * (unknown counts as changed), and so does setAttribute(). quote(),
 * getAttribute(), lastInsertId(), inTransaction(), errorCode() and
 * errorInfo() change nothing. beginTransaction(), commit() and rollBack()
 * count as clean: a finished transaction leaves no session state, and a
 * connection handed back inside one is never reused (the pool drops it).
 * A statement re-executed later runs SQL that prepare() already judged, and
 * nextRowset() only exists for SQL that is never clean (several statements,
 * CALL), so statements need no tracking of their own.
 */
class NativePdo extends Mysql implements GuardedPdo
{
    private readonly LeaseGuard $leaseGuard;

    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);

        $this->leaseGuard = new LeaseGuard;

        parent::setAttribute(PDO::ATTR_STATEMENT_CLASS, [NativePdoStatement::class, [$this->leaseGuard]]);
    }

    public function leaseGuard(): LeaseGuard
    {
        return $this->leaseGuard;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->leaseGuard->run(function () use ($query, $options) {
            $this->noteStatement($query);

            return parent::prepare($query, $options);
        });
    }

    public function exec(string $statement): int|false
    {
        return $this->leaseGuard->run(function () use ($statement) {
            $this->noteStatement($statement);

            return parent::exec($statement);
        });
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->leaseGuard->run(function () use ($query, $fetchMode, $fetchModeArgs) {
            $this->noteStatement($query);

            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        });
    }

    public function beginTransaction(): bool
    {
        return $this->leaseGuard->run(fn () => parent::beginTransaction());
    }

    public function commit(): bool
    {
        return $this->leaseGuard->run(fn () => parent::commit());
    }

    public function rollBack(): bool
    {
        return $this->leaseGuard->run(fn () => parent::rollBack());
    }

    /**
     * Some attributes reach the session (ATTR_AUTOCOMMIT sends SET autocommit),
     * and every one stays on this PDO for the next lessee: always a change.
     * Guarded like the SQL methods, so a stale reference can neither change an
     * idle connection (assign() would clear the mark before the next lessee)
     * nor another fiber's live lease. Unguarded while the connector sets up.
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->leaseGuard->run(function () use ($attribute, $value) {
            $this->leaseGuard->markSessionChanged();

            return parent::setAttribute($attribute, $value);
        });
    }

    /**
     * ATTR_SERVER_INFO sends COM_STATISTICS, so it is guarded like a query
     * (and changes nothing). Every other pdo_mysql attribute is read from the
     * client side without a round trip and stays unguarded.
     */
    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_SERVER_INFO) {
            return $this->leaseGuard->run(fn () => parent::getAttribute($attribute));
        }

        return parent::getAttribute($attribute);
    }

    /**
     * FledgePdo's fail-safe classifier, plus two allowances for a lease that
     * is judged only when its scope ends:
     * - reading CONNECTION_ID(), DATABASE(), SCHEMA(), FOUND_ROWS() or
     *   ROW_COUNT() reads the session without changing it, so nothing it read
     *   reaches the next lessee (FledgePdo counts them as dirty because it may
     *   hand a pin over between two statements of one owner);
     * - SAVEPOINT, ROLLBACK TO SAVEPOINT and RELEASE SAVEPOINT: a savepoint
     *   lives only as long as its transaction, and a connection handed back
     *   inside a transaction is never reused.
     */
    public static function leavesSessionUnchanged(string $query): bool
    {
        if (preg_match('/^\s*(?:savepoint|rollback\s+(?:work\s+)?to(?:\s+savepoint)?|release\s+savepoint)\s+(?:[\w$]+|`(?:[^`]|``)+`)\s*;?\s*$/i', $query) === 1) {
            return true;
        }

        $query = preg_replace('/\b(?:connection_id|database|schema|found_rows|row_count)\s*\(\s*\)/i', 'count(*)', $query);

        return $query !== null && FledgePdo::leavesSessionUnchanged($query);
    }

    private function noteStatement(string $query): void
    {
        if (! $this->leaseGuard->sessionChanged() && ! self::leavesSessionUnchanged($query)) {
            $this->leaseGuard->markSessionChanged();
        }
    }
}
