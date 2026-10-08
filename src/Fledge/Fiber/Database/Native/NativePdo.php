<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use PDO;
use Pdo\Mysql;
use PDOStatement;

/**
 * Stock pdo_mysql connection with a lease-owner assertion on every call that
 * talks to the server.
 *
 * Only the socket-touching entry points are guarded; quote(), lastInsertId(),
 * inTransaction() and attribute reads stay native. Statements come out as
 * NativePdoStatement, which guards execute().
 */
class NativePdo extends Mysql implements GuardedPdo
{
    private readonly LeaseGuard $leaseGuard;

    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);

        $this->leaseGuard = new LeaseGuard;

        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [NativePdoStatement::class, [$this->leaseGuard]]);
    }

    public function leaseGuard(): LeaseGuard
    {
        return $this->leaseGuard;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->leaseGuard->run(fn () => parent::prepare($query, $options));
    }

    public function exec(string $statement): int|false
    {
        return $this->leaseGuard->run(fn () => parent::exec($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->leaseGuard->run(fn () => parent::query($query, $fetchMode, ...$fetchModeArgs));
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
}
