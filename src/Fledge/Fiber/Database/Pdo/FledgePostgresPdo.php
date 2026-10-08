<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\Database\SqlConnectionPool;
use PDO;

/**
 * PostgreSQL-specific FledgePdo implementation wrapping PostgresConnectionPool.
 *
 * Translates PDO-style ? placeholders to PostgreSQL $N style.
 */
class FledgePostgresPdo extends FledgePdo
{
    private const int TOKEN_TEXT = 0;

    private const int TOKEN_PLACEHOLDER = 1;

    private const int TOKEN_ESCAPED_QUESTION = 2;

    private const int TOKEN_DOLLAR_QUOTE = 3;

    /**
     * The server's standard_conforming_strings setting, once read.
     */
    protected ?bool $standardConformingStrings = null;

    public function __construct(SqlConnectionPool $pool)
    {
        parent::__construct($pool);
    }

    protected function getVersionQuery(): string
    {
        return 'SELECT version()';
    }

    protected function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * Quote a string for use in a query, matching pdo_pgsql's quoter.
     *
     * pdo_pgsql runs PQescapeStringConn for every type except PARAM_LOB, so
     * PARAM_INT values are quoted as strings too. libpq doubles single quotes and,
     * only when the connection reports standard_conforming_strings=off, backslashes
     * as well; it never emits an E'' literal. libpq stops at the first NUL byte and
     * rejects invalid multibyte input, in which case PDO::quote() returns false.
     * PARAM_LOB goes through PQescapeByteaConn, which yields the hex bytea format.
     *
     * The setting is only consulted when the value contains a backslash. If it
     * cannot be read, the value is emitted as an E'' literal with both backslashes
     * and quotes doubled, which is safe under either setting.
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->guard(function () use ($string, $type): string|false {
            if ($type === PDO::PARAM_LOB) {
                return $this->quoteEscaped('\\x'.bin2hex($string));
            }

            $nul = strpos($string, "\0");

            if ($nul !== false) {
                $string = substr($string, 0, $nul);
            }

            if (! mb_check_encoding($string, 'UTF-8')) {
                return false;
            }

            return $this->quoteEscaped($string);
        });
    }

    /**
     * Wrap a value in a string literal that is safe under the connection's
     * standard_conforming_strings setting.
     */
    protected function quoteEscaped(string $string): string
    {
        $quoted = str_replace("'", "''", $string);

        if (! str_contains($string, '\\')) {
            return "'{$quoted}'";
        }

        return match ($this->standardConformingStrings()) {
            true => "'{$quoted}'",
            false => "'".str_replace('\\', '\\\\', $quoted)."'",
            null => "E'".str_replace('\\', '\\\\', $quoted)."'",
        };
    }

    /**
     * Read standard_conforming_strings from the server (the connection pinned by the
     * open transaction, if the current fiber owns it), caching a known answer. Returns null when unknown.
     */
    protected function standardConformingStrings(): ?bool
    {
        if ($this->standardConformingStrings !== null) {
            return $this->standardConformingStrings;
        }

        try {
            $row = ($this->ownsTransaction() ? ($this->transaction ?? $this->pool) : $this->pool)
                ->query('SHOW standard_conforming_strings')
                ->fetchRow();
        } catch (\Throwable) {
            return null;
        }

        return $this->standardConformingStrings = match (is_array($row) ? strtolower((string) reset($row)) : null) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    /**
     * pdo_pgsql reads the libpq transaction status, which turns PQTRANS_UNKNOWN (false)
     * once the server has dropped the connection. Mirror that by consulting the pinned
     * transaction and releasing it once the driver reports it inactive.
     */
    public function inTransaction(): bool
    {
        return $this->guard(function (): bool {
            $this->releaseInactiveTransaction();

            return $this->transaction !== null;
        });
    }

    /**
     * Begin a transaction, first releasing a pinned transaction the server already ended.
     */
    public function beginTransaction(): bool
    {
        $this->assertOwnsTransaction();

        return $this->guard(function (): bool {
            $this->releaseInactiveTransaction();

            return parent::beginTransaction();
        });
    }

    /**
     * Commit, throwing "There is no active transaction" like PDO when the server already ended it.
     */
    public function commit(): bool
    {
        $this->assertOwnsTransaction();

        return $this->guard(function (): bool {
            $this->releaseInactiveTransaction();

            return parent::commit();
        });
    }

    /**
     * Roll back, throwing "There is no active transaction" like PDO when the server already ended it.
     */
    public function rollBack(): bool
    {
        $this->assertOwnsTransaction();

        return $this->guard(function (): bool {
            $this->releaseInactiveTransaction();

            return parent::rollBack();
        });
    }

    protected function releaseInactiveTransaction(): void
    {
        if ($this->transaction !== null && ! $this->transaction->isActive()) {
            $this->transaction = null;
            $this->transactionFiber = null;
        }
    }

    public function trackLastInsertId(mixed $result): void
    {
        // PostgreSQL uses RETURNING clauses rather than a global last_insert_id.
    }

    /**
     * Prepare a statement, translating ? placeholders to $N for PostgreSQL.
     */
    public function prepare(string $query, array $options = []): FledgePdoStatement
    {
        $this->assertOwnsTransaction();

        return $this->guard(fn () => parent::prepare($this->convertPlaceholders($query), $options));
    }

    /**
     * Convert PDO-style ? placeholders to PostgreSQL $N style.
     *
     * Mirrors PDO's parser with the pdo_pgsql scanner (ext/pdo_pgsql/pgsql_sql_parser.re
     * and ext/pdo/pdo_sql_parser.re): '...', E'...' and "..." literals, -- and
     * non-nested block comments, and $tag$...$tag$ dollar quoting are copied
     * verbatim; ?? outside a literal becomes a literal ? (the PDO 8.4+ escape
     * used for jsonb operators). Like PDO, ?? inside a dollar-quoted body is
     * also unescaped, while a lone ? there stays as is.
     */
    protected function convertPlaceholders(string $query): string
    {
        $result = '';
        $paramIndex = 1;
        $dollarTag = null;
        $len = strlen($query);
        $i = 0;

        while ($i < $len) {
            [$type, $end] = $this->scanToken($query, $i, $len);
            $token = substr($query, $i, $end - $i);
            $i = $end;

            if ($dollarTag !== null) {
                if ($type === self::TOKEN_DOLLAR_QUOTE && $token === $dollarTag) {
                    $dollarTag = null;
                }

                $result .= $type === self::TOKEN_ESCAPED_QUESTION ? '?' : $token;

                continue;
            }

            $result .= match ($type) {
                self::TOKEN_PLACEHOLDER => '$'.$paramIndex++,
                self::TOKEN_ESCAPED_QUESTION => '?',
                default => $token,
            };

            if ($type === self::TOKEN_DOLLAR_QUOTE) {
                $dollarTag = $token;
            }
        }

        return $result;
    }

    /**
     * Scan one token starting at $i, following the longest-match rules of pdo_pgsql's scanner.
     *
     * @return array{int, int} The token type and the offset just past it.
     */
    private function scanToken(string $query, int $i, int $len): array
    {
        $char = $query[$i];
        $next = $query[$i + 1] ?? '';

        if (($char === 'e' || $char === 'E') && $next === "'") {
            $end = $this->matchQuoted($query, $i + 1, $len, "'", true);

            return [self::TOKEN_TEXT, $end ?? $i + 1];
        }

        if ($char === "'" || $char === '"') {
            return [self::TOKEN_TEXT, $this->matchQuoted($query, $i, $len, $char, false) ?? $i + 1];
        }

        if ($char === '$') {
            $j = $i + 1;

            if ($j < $len && $this->isDollarTagChar($query[$j], true)) {
                $j++;

                while ($j < $len && $this->isDollarTagChar($query[$j], false)) {
                    $j++;
                }
            }

            if ($j < $len && $query[$j] === '$') {
                return [self::TOKEN_DOLLAR_QUOTE, $j + 1];
            }

            return [self::TOKEN_TEXT, $i + 1];
        }

        if ($char === '?') {
            return $next === '?'
                ? [self::TOKEN_ESCAPED_QUESTION, $i + 2]
                : [self::TOKEN_PLACEHOLDER, $i + 1];
        }

        if ($char === ':') {
            $j = $i + 1;

            while ($j < $len && $query[$j] === ':') {
                $j++;
            }

            return [self::TOKEN_TEXT, $j];
        }

        if ($char === '-' && $next === '-') {
            $newline = strpos($query, "\n", $i + 2);

            return [self::TOKEN_TEXT, $newline === false ? $len : $newline];
        }

        if ($char === '/' && $next === '*') {
            $close = strpos($query, '*/', $i + 2);

            return [self::TOKEN_TEXT, $close === false ? $i + 1 : $close + 2];
        }

        $run = strcspn($query, "\$eE:?\"'/-", $i);

        return [self::TOKEN_TEXT, $i + max(1, $run)];
    }

    /**
     * Match a quoted literal opening at $start, returning the offset past the closing quote.
     *
     * A doubled quote may continue the literal; when the input ends before a later
     * closing quote, the longest complete match wins, as with re2c. Backslash escapes
     * only apply to E'' strings. Returns null when the literal is never closed.
     */
    private function matchQuoted(string $query, int $start, int $len, string $quote, bool $backslashEscapes): ?int
    {
        $longest = null;
        $i = $start + 1;

        while ($i < $len) {
            $char = $query[$i];

            if ($char === $quote) {
                $longest = $i + 1;

                if (($query[$i + 1] ?? '') !== $quote) {
                    break;
                }

                $i += 2;

                continue;
            }

            if ($backslashEscapes && $char === '\\') {
                if ($i + 1 >= $len) {
                    break;
                }

                $i += 2;

                continue;
            }

            $i++;
        }

        return $longest;
    }

    private function isDollarTagChar(string $char, bool $first): bool
    {
        return $char === '_'
            || ctype_alpha($char)
            || ord($char) >= 0x80
            || (! $first && ctype_digit($char));
    }
}
