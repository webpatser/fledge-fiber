<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlQueryError;
use PDOException;

/**
 * PDOException shaped like the ones pdo_mysql and pdo_pgsql throw.
 *
 * Illuminate\Database only recognises driver errors that look like PDO's:
 * MySqlConnection matches "Integrity constraint violation: 1062" in the message,
 * PostgresConnection compares getCode() with "23505", and the concurrency
 * detector checks for a PDOException whose code is "40001". Translating
 * SqlQueryError into this shape keeps unique-constraint handling
 * (createOrFirst, firstOrCreate) and deadlock retries working.
 */
class FledgePdoException extends PDOException
{
    /**
     * PDO's descriptions for specific SQLSTATEs, checked before the class fallback.
     */
    private const STATE_DESCRIPTIONS = [
        '23502' => 'Not null violation',
        '23503' => 'Foreign key violation',
        '23505' => 'Unique violation',
        '40P01' => 'Deadlock detected',
        '42S02' => 'Base table or view not found',
        '42S22' => 'Column not found',
    ];

    /**
     * PDO's descriptions per SQLSTATE class (first two characters).
     */
    private const CLASS_DESCRIPTIONS = [
        '23' => 'Integrity constraint violation',
        '40' => 'Serialization failure',
        '42' => 'Syntax error or access violation',
    ];

    /**
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    public function __construct(string $message, string $sqlState, array $errorInfo, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        // PDO exposes the SQLSTATE string as the exception code.
        $this->code = $sqlState;
        $this->errorInfo = $errorInfo;
    }

    /**
     * Translate a Fledge Async query error into a PDO-shaped exception.
     *
     * @param  int|null  $driverCode  Overrides the native code in the message and errorInfo[1]
     *                                (pdo_pgsql reports the libpq result status, not a server error number).
     */
    public static function fromQueryError(SqlQueryError $error, ?int $driverCode = null): self
    {
        $sqlState = $error->getSqlState() ?? 'HY000';
        $code = $driverCode ?? $error->getErrorCode();
        $message = rtrim($error->getServerMessage());

        return new self(
            sprintf('SQLSTATE[%s]: %s: %d %s', $sqlState, self::describe($sqlState), $code, $message),
            $sqlState,
            [$sqlState, $code, $message],
            $error,
        );
    }

    /**
     * PDO's human-readable description of a SQLSTATE.
     */
    public static function describe(string $sqlState): string
    {
        return self::STATE_DESCRIPTIONS[$sqlState]
            ?? self::CLASS_DESCRIPTIONS[substr($sqlState, 0, 2)]
            ?? 'General error';
    }
}
