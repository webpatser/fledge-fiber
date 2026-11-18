<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

/**
 * What a pooled transaction did to its connection's session: the pool takes the connection
 * back as clean only when the transaction ended through commit() or rollback(), every
 * statement in it was transaction-scoped (see PostgresSessionClassifier) and none failed.
 * A failed statement counts as dirty: the transaction was aborted, and a DEALLOCATE sent
 * meanwhile left its prepared statement on the server.
 *
 * @internal
 */
final class PostgresSessionTracker
{
    /** True once a statement that may outlive the transaction ran, or anything failed. */
    public bool $dirty = false;

    /** True once the top-level transaction ended cleanly. */
    public bool $clean = false;

    public function note(string $sql): void
    {
        if (!$this->dirty && !PostgresSessionClassifier::leavesSessionUnchanged($sql)) {
            $this->dirty = true;
        }
    }

    public function fail(): void
    {
        $this->dirty = true;
    }

    public function end(): void
    {
        $this->clean = !$this->dirty;
    }
}
