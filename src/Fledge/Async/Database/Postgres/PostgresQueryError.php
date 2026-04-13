<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Database\SqlQueryError;

class PostgresQueryError extends SqlQueryError
{
    /**
     * @param array<non-empty-string, scalar|null> $diagnostics
     */
    public function __construct(
        string $message,
        private readonly array $diagnostics,
        string $query,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $query, $previous);
    }

    /**
     * @return array<non-empty-string, scalar|null>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
