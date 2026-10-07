<?php declare(strict_types=1);

namespace Fledge\Async\Database;

class SqlQueryError extends \Error
{
    /**
     * @param int $errorCode Driver-native error number (e.g. MySQL 1062), 0 when unknown.
     * @param string|null $sqlState Five-character SQLSTATE reported by the server, null when unknown.
     * @param string|null $serverMessage The server's raw error text, without driver prefixes.
     */
    public function __construct(
        string $message,
        protected readonly string $query = "",
        ?\Throwable $previous = null,
        protected readonly int $errorCode = 0,
        protected readonly ?string $sqlState = null,
        protected readonly ?string $serverMessage = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }

    final public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Driver-native error number (e.g. MySQL 1062 or 1213), 0 when unknown.
     */
    final public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * Five-character SQLSTATE (e.g. "23000", "40001"), null when unknown.
     */
    final public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * The server's raw error text, falling back to the exception message.
     */
    final public function getServerMessage(): string
    {
        return $this->serverMessage ?? $this->message;
    }

    public function __toString(): string
    {
        if ($this->query === "") {
            return parent::__toString();
        }

        $msg = $this->message;
        $this->message .= "\nCurrent query was {$this->query}";
        $str = parent::__toString();
        $this->message = $msg;
        return $str;
    }
}
