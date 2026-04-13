<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

final readonly class PostgresArray
{
    public function __construct(
        private array $data,
        private string $delimiter = ',',
    ) {
    }

    /**
     * Encodes the array for use in a query. Note that the value returned must still be quoted using
     * {@see PostgresExecutor::quoteLiteral()} if inserting directly into a query string.
     */
    public function encode(PostgresExecutor $executor): string
    {
        return Internal\encodeArray($executor, $this->data, $this->delimiter);
    }
}
