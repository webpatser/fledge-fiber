<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Database\SqlQueryError;

/**
 * @extends SqlConnection<PostgresConfig, PostgresResult, PostgresStatement, PostgresTransaction>
 */
interface PostgresConnection extends PostgresLink, SqlConnection
{
    /**
     * @return PostgresConfig Config object specific to this library.
     */
    #[\Override]
    public function getConfig(): PostgresConfig;

    /**
     * @param non-empty-string $channel Channel name.
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws SqlConnectionException If the connection to the database is lost.
     * @throws SqlQueryError If the operation fails due to an error in the query (such as a syntax error).
     */
    public function listen(string $channel): PostgresListener;
}
