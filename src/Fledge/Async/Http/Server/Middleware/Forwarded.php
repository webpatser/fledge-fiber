<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Middleware;

use Fledge\Async\Stream\InternetAddress;

final readonly class Forwarded
{
    /**
     * @param array<non-empty-string, string|null> $fields
     */
    public function __construct(
        private InternetAddress $for,
        private array $fields,
    ) {
    }

    public function getFor(): InternetAddress
    {
        return $this->for;
    }

    public function getField(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }
}
