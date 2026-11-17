<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\Mysql\MysqlConnectionPool;
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
     * Backslash escapes of mysqlnd's mysqlnd_cset_escape_slashes(), except that a quote is doubled
     * (the same value as \' to the server, and still a quote in a NO_BACKSLASH_ESCAPES session).
     */
    private const ESCAPES = [
        "\0" => '\0',
        "\n" => '\n',
        "\r" => '\r',
        "\x1a" => '\Z',
        "'" => "''",
        '"' => '\"',
        '\\' => '\\\\',
    ];

    /**
     * Charsets whose multibyte characters may end in an ASCII byte such as a backslash, so escaping
     * byte by byte would let a lead byte swallow the escape (mysqlnd escapes these per character).
     */
    private const MULTIBYTE_CHARSETS = ['big5' => true, 'gbk' => true, 'sjis' => true, 'cp932' => true, 'gb18030' => true];

    /**
     * Quote a string for use in a query.
     *
     * Manual escaping for MySQL: Fledge Async MySQL does not provide a quote method. Follows mysqlnd's
     * mysql_real_escape_string_quote(), except that a quote is always doubled ('' where mysqlnd writes
     * \'), because the query may run on another pooled connection than the one quote() looked at. When
     * every open connection reports NO_BACKSLASH_ESCAPES nothing else is escaped; otherwise (including
     * unknown or disagreeing sessions) backslashes and the other special bytes are backslash escaped per
     * character of the connection charset. The result is safe on any connection unless all known
     * connections report NO_BACKSLASH_ESCAPES and the statement then runs on a connection without it,
     * which the connector's per-connection sql_mode prevents unless the application changes sql_mode itself.
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->guard(function () use ($string, $type): string {
            if ($type === PDO::PARAM_INT) {
                return (string) (int) $string;
            }

            $noBackslashEscapes = false;
            $charset = '';

            if ($this->pool instanceof MysqlConnectionPool) {
                $noBackslashEscapes = $this->pool->isBackslashEscapingDisabled();
                $charset = strtolower($this->pool->getConfig()->getCharset());
            }

            if ($noBackslashEscapes === true) {
                return "'".str_replace("'", "''", $string)."'";
            }

            return "'".$this->escape($string, $charset, self::ESCAPES)."'";
        });
    }

    /**
     * @param  array<string, string>  $escapes
     */
    private function escape(string $string, string $charset, array $escapes): string
    {
        if (! isset(self::MULTIBYTE_CHARSETS[$charset])) {
            return strtr($string, $escapes);
        }

        $escaped = '';
        $length = strlen($string);

        for ($i = 0; $i < $length; $i++) {
            $sequence = $this->multibyteSequenceLength($string, $i, $length, $charset);

            if ($sequence > 0) {
                $escaped .= substr($string, $i, $sequence);
                $i += $sequence - 1;

                continue;
            }

            $byte = $string[$i];

            // A lead byte without a valid trail is escaped itself, so it cannot pair with the next byte.
            $escaped .= $this->isMultibyteLead(ord($byte), $charset) ? '\\'.$byte : ($escapes[$byte] ?? $byte);
        }

        return $escaped;
    }

    /**
     * Length of the valid multibyte character at $offset, or 0 (mysqlnd's mb_valid per charset).
     */
    private function multibyteSequenceLength(string $string, int $offset, int $length, string $charset): int
    {
        $lead = ord($string[$offset]);

        if (! $this->isMultibyteLead($lead, $charset) || $length - $offset < 2) {
            return 0;
        }

        $trail = ord($string[$offset + 1]);

        $valid = match ($charset) {
            'big5' => ($trail >= 0x40 && $trail <= 0x7E) || ($trail >= 0xA1 && $trail <= 0xFE),
            'gbk', 'gb18030' => ($trail >= 0x40 && $trail <= 0x7E) || ($trail >= 0x80 && $trail <= 0xFE),
            'sjis', 'cp932' => ($trail >= 0x40 && $trail <= 0x7E) || ($trail >= 0x80 && $trail <= 0xFC),
        };

        if ($valid) {
            return 2;
        }

        if ($charset === 'gb18030'
            && $length - $offset >= 4
            && $trail >= 0x30 && $trail <= 0x39
            && ord($string[$offset + 2]) >= 0x81 && ord($string[$offset + 2]) <= 0xFE
            && ord($string[$offset + 3]) >= 0x30 && ord($string[$offset + 3]) <= 0x39
        ) {
            return 4;
        }

        return 0;
    }

    private function isMultibyteLead(int $byte, string $charset): bool
    {
        return match ($charset) {
            'big5' => $byte >= 0xA1 && $byte <= 0xF9,
            'gbk', 'gb18030' => $byte >= 0x81 && $byte <= 0xFE,
            'sjis', 'cp932' => ($byte >= 0x81 && $byte <= 0x9F) || ($byte >= 0xE0 && $byte <= 0xFC),
            default => false,
        };
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
