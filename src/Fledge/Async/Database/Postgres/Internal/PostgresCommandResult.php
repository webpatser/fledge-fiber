<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\SqlCommandResult;

/**
 * @internal
 * @psalm-import-type TFieldType from PostgresResult
 * @extends SqlCommandResult<TFieldType, PostgresResult>
 */
final class PostgresCommandResult extends SqlCommandResult implements PostgresResult
{
    /**
     * Changes return type to this library's Result type.
     */
    #[\Override]
    public function getNextResult(): ?PostgresResult
    {
        return parent::getNextResult();
    }
}
