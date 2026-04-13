<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

use Fledge\Async\Database\Postgres\PostgresResult;
use Fledge\Async\Database\SqlPooledResult;
use Fledge\Async\Database\SqlResult;

/**
 * @internal
 * @psalm-import-type TFieldType from PostgresResult
 * @extends SqlPooledResult<TFieldType, PostgresResult>
 */
final class PostgresPooledResult extends SqlPooledResult implements PostgresResult
{
    #[\Override]
    protected static function newInstanceFrom(SqlResult $result, \Closure $release): self
    {
        \assert($result instanceof PostgresResult);
        return new self($result, $release);
    }

    #[\Override]
    public function getNextResult(): ?PostgresResult
    {
        return parent::getNextResult();
    }
}
