<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server;

final class HttpErrorException extends \Exception
{
    public function __construct(
        private int $status,
        private ?string $reason = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: 'Error ' . $status . ($this->reason !== null && $this->reason !== '' ? ': ' . $reason : ''),
            previous: $previous,
        );
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
