<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Database\SqlLink;

/**
 * @extends SqlLink<PostgresResult, PostgresStatement, PostgresTransaction>
 */
interface PostgresLink extends PostgresExecutor, SqlLink
{
    /**
     * @return PostgresTransaction Transaction object specific to this library.
     */
    #[\Override]
    public function beginTransaction(): PostgresTransaction;
}
