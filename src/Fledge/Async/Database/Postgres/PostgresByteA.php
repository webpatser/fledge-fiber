<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

final class PostgresByteA
{
    public function __construct(
        private readonly string $data,
    ) {
    }

    public function getData(): string
    {
        return $this->data;
    }
}
