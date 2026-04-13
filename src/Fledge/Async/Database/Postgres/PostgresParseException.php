<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Database\SqlException;

final class PostgresParseException extends SqlException
{
    public function __construct(string $message = '')
    {
        $message = "Parse error while splitting array" . (($message === '') ? '' : ": " . $message);
        parent::__construct($message);
    }
}
