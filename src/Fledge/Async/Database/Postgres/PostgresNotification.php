<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

final readonly class PostgresNotification
{
    /**
     * @param non-empty-string $channel Channel name.
     * @param positive-int $pid PID of message source.
     * @param string $payload Message payload.
     */
    public function __construct(
        public string $channel,
        public int $pid,
        public string $payload,
    ) {
    }
}
