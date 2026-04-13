<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\SqlConnectionPool;
use PDO;

/**
 * MySQL-specific FledgePdo implementation wrapping MysqlConnectionPool.
 */
class FledgeMySqlPdo extends FledgePdo
{
    public function __construct(SqlConnectionPool $pool)
    {
        parent::__construct($pool);
    }

    protected function getVersionQuery(): string
    {
        return 'SELECT VERSION()';
    }

    protected function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * Quote a string for use in a query.
     *
     * Manual escaping for MySQL — Fledge Async MySQL does not provide a quote method.
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        if ($type === PDO::PARAM_INT) {
            return (string) (int) $string;
        }

        $escaped = strtr($string, [
            "\0" => '\0',
            "\n" => '\n',
            "\r" => '\r',
            "\x1a" => '\Z',
            "'" => "\'",
            '"' => '\"',
            '\\' => '\\\\',
        ]);

        return "'{$escaped}'";
    }

    public function trackLastInsertId(mixed $result): void
    {
        if ($result instanceof MysqlResult) {
            $id = $result->getLastInsertId();

            if ($id !== null) {
                $this->lastInsertId = (string) $id;
            }
        }
    }
}
