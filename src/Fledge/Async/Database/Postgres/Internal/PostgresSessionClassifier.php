<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres\Internal;

/**
 * Decides from the SQL text whether a statement can leave state behind in a Postgres
 * session, so the pool resets (DISCARD ALL) only connections whose session may have changed.
 *
 * Fails safe: a statement counts as clean only when it is one plain SELECT / WITH / VALUES /
 * TABLE / SHOW / EXPLAIN (without ANALYZE), or a transaction-scoped command (SET LOCAL, SET
 * TRANSACTION, SET CONSTRAINTS, SAVEPOINT, RELEASE, ROLLBACK TO, DECLARE without HOLD, FETCH,
 * MOVE, CLOSE) or NOTIFY, with no INSERT / UPDATE / DELETE / MERGE (a trigger on the table can
 * do anything a function can, such as moving lastval or calling set_config(), and the text
 * cannot show whether a table has one; a serial default calls nextval, and currval / lastval
 * read what it left), no DEFAULT, no INTO, no TEMP, no comment,
 * no second statement, no backslash inside a plain string literal (its meaning depends on
 * standard_conforming_strings), and no call except the built-ins in BUILTIN_CALLS (a
 * schema-qualified or quoted name counts as a user function, which can do anything).
 * Anything else, SET, LISTEN, PREPARE, DO, COPY, set_config() and the session advisory lock
 * functions included, counts as changing the session.
 *
 * Residual risk: code that a plain SELECT reaches without naming it as a call cannot be seen
 * in the text, so a session change it makes can go to the next fiber with the connection:
 * a function or rule behind a view (a view whose definition calls a function that runs
 * set_config()), a function called in attribute notation (SELECT t.fn FROM t, a user function
 * taking the row type, which reads like a column), and user-defined operators and casts whose
 * implementing function changes the session.
 *
 * @internal
 */
final class PostgresSessionClassifier
{
    /**
     * Dollar-quoted strings, E'' strings, plain '' strings and "" identifiers, left to right.
     * $ may continue an identifier (a$x$ is a name), so a dollar-quote opener is never
     * preceded by an identifier character, nor is an E'' prefix.
     */
    private const LITERALS = <<<'REGEX'
/(?<![\w\x80-\xff$])(\$(?:[A-Za-z_\x80-\xff][\w\x80-\xff]*)?\$).*?\1|(?<![\w$])[eE]'(?:[^'\\]|\\.|'')*'|'(?:[^']|'')*'|"(?:[^"]|"")*"/s
REGEX;

    public static function leavesSessionUnchanged(string $query): bool
    {
        $unsafe = false;

        // Literals become "lit" and quoted identifiers "x", so their contents are not read as SQL.
        $sql = \preg_replace_callback(self::LITERALS, static function (array $match) use (&$unsafe): string {
            $token = $match[0];

            if ($token[0] === '"') {
                return ' x ';
            }

            if ($token[0] === "'" && \str_contains($token, '\\')) {
                $unsafe = true;
            }

            return ' lit ';
        }, $query);

        if ($sql === null || $unsafe) {
            return false;
        }

        // One trailing semicolon ends the statement; anything after one is a second statement.
        $sql = \rtrim(\trim($sql), "; \t\r\n");

        // A leftover quote or $ (other than a $1 placeholder) is a literal the scanner could not close.
        if ($sql === '' || \preg_match('/;|--|\/\*|[\'"\\\\]|\$(?!\d)/', $sql)) {
            return false;
        }

        if (!\preg_match('/^[\s(]*([a-z_]+)\b/i', $sql, $verb)) {
            return false;
        }

        return match (\strtolower($verb[1])) {
            'select', 'with', 'values', 'table' => self::isPlainQuery($sql),
            'explain' => !\preg_match('/\banaly[sz]e\b/i', $sql) && self::isPlainQuery($sql),
            'show' => \preg_match('/^show\s+[\w.]+$/i', $sql) === 1,
            'set' => \preg_match('/^set\s+(local|transaction|constraints)\b/i', $sql) === 1 && self::isPlainQuery($sql),
            'declare' => !\preg_match('/\bwith\s+hold\b/i', $sql) && self::isPlainQuery($sql),
            'rollback' => \preg_match('/^rollback\s+(?:work\s+|transaction\s+)?to\b/i', $sql) === 1,
            'savepoint', 'release', 'fetch', 'move', 'close', 'notify' => \preg_match('/\(/', $sql) === 0,
            default => false,
        };
    }

    private static function isPlainQuery(string $sql): bool
    {
        // FOR [NO KEY] UPDATE is a row lock that ends with the transaction, not DML.
        $locks = \preg_replace('/\bfor\s+(?:no\s+key\s+)?update\b/i', 'for lock', $sql);

        if ($locks === null || \preg_match('/\b(insert|update|delete|merge|into|default|temp|temporary)\b/i', $locks)) {
            return false;
        }

        // schema.fn(...) or "fn"(...): a user function.
        if (\preg_match('/\.\s*[a-z_\x80-\xff][\w\x80-\xff$]*\s*\(|\bx\s*\(/i', $sql)) {
            return false;
        }

        // A byte above 0x7f is part of a name (ñcount is not count), and so is $.
        \preg_match_all('/(?<![\w\x80-\xff$])([a-z_\x80-\xff][\w\x80-\xff$]*)\s*\(/i', $sql, $calls);

        foreach ($calls[1] as $name) {
            if (!isset(self::BUILTIN_CALLS[\strtolower($name)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keywords, type names and built-in functions that may appear before "(" in a statement
     * that leaves the session unchanged. Functions that change session state (nextval,
     * setval, set_config, the session advisory lock functions, pg_notify) are left out, and
     * so are currval and lastval.
     */
    private const BUILTIN_CALLS = [
        // Keywords.
        'select' => true, 'in' => true, 'values' => true, 'exists' => true, 'and' => true, 'or' => true,
        'not' => true, 'as' => true, 'on' => true, 'using' => true, 'from' => true, 'join' => true, 'is' => true,
        'where' => true, 'over' => true, 'union' => true, 'all' => true, 'any' => true, 'some' => true,
        'with' => true, 'by' => true, 'having' => true, 'then' => true, 'else' => true, 'when' => true,
        'case' => true, 'like' => true, 'ilike' => true, 'similar' => true, 'between' => true, 'lateral' => true,
        'except' => true, 'intersect' => true, 'distinct' => true, 'filter' => true, 'within' => true,
        'group' => true, 'order' => true, 'partition' => true, 'row' => true, 'array' => true, 'set' => true,
        'limit' => true, 'offset' => true, 'materialized' => true, 'returning' => true, 'only' => true,
        'cube' => true, 'rollup' => true, 'grouping' => true, 'sets' => true, 'tablesample' => true,
        'bernoulli' => true, 'system' => true, 'repeatable' => true, 'explain' => true, 'for' => true,
        'cast' => true, 'extract' => true, 'position' => true, 'overlay' => true, 'substring' => true,
        'trim' => true, 'interval' => true, 'nullif' => true, 'coalesce' => true, 'greatest' => true, 'least' => true,
        // Type names with a modifier.
        'numeric' => true, 'decimal' => true, 'varchar' => true, 'char' => true, 'character' => true,
        'varying' => true, 'bit' => true, 'timestamp' => true, 'timestamptz' => true, 'time' => true,
        'timetz' => true, 'float' => true,
        // Aggregates and window functions.
        'count' => true, 'sum' => true, 'min' => true, 'max' => true, 'avg' => true, 'string_agg' => true,
        'array_agg' => true, 'json_agg' => true, 'jsonb_agg' => true, 'json_object_agg' => true,
        'jsonb_object_agg' => true, 'bool_and' => true, 'bool_or' => true, 'every' => true, 'bit_and' => true,
        'bit_or' => true, 'stddev' => true, 'variance' => true, 'percentile_cont' => true,
        'percentile_disc' => true, 'mode' => true, 'row_number' => true, 'rank' => true, 'dense_rank' => true,
        'percent_rank' => true, 'cume_dist' => true, 'ntile' => true, 'lag' => true, 'lead' => true,
        'first_value' => true, 'last_value' => true, 'nth_value' => true,
        // Strings.
        'lower' => true, 'upper' => true, 'length' => true, 'char_length' => true, 'character_length' => true,
        'octet_length' => true, 'bit_length' => true, 'substr' => true, 'strpos' => true, 'btrim' => true,
        'ltrim' => true, 'rtrim' => true, 'left' => true, 'right' => true, 'lpad' => true, 'rpad' => true,
        'replace' => true, 'reverse' => true, 'repeat' => true, 'concat' => true, 'concat_ws' => true,
        'split_part' => true, 'md5' => true, 'sha224' => true, 'sha256' => true, 'sha384' => true,
        'sha512' => true, 'encode' => true, 'decode' => true, 'format' => true, 'initcap' => true,
        'translate' => true, 'regexp_replace' => true, 'regexp_match' => true, 'regexp_matches' => true,
        'regexp_split_to_array' => true, 'regexp_split_to_table' => true, 'regexp_like' => true,
        'starts_with' => true, 'string_to_array' => true, 'string_to_table' => true, 'quote_ident' => true,
        'quote_literal' => true, 'quote_nullable' => true,
        // Arrays.
        'array_length' => true, 'array_position' => true, 'array_positions' => true, 'array_to_string' => true,
        'array_append' => true, 'array_prepend' => true, 'array_remove' => true, 'array_cat' => true,
        'array_dims' => true, 'array_lower' => true, 'array_upper' => true, 'unnest' => true, 'cardinality' => true,
        // Numbers.
        'abs' => true, 'round' => true, 'floor' => true, 'ceil' => true, 'ceiling' => true, 'mod' => true,
        'power' => true, 'sqrt' => true, 'cbrt' => true, 'exp' => true, 'ln' => true, 'log' => true,
        'trunc' => true, 'sign' => true, 'pi' => true, 'random' => true, 'div' => true, 'width_bucket' => true,
        'num_nulls' => true, 'num_nonnulls' => true,
        // Dates and times.
        'to_char' => true, 'to_date' => true, 'to_timestamp' => true, 'to_number' => true, 'date_trunc' => true,
        'date_part' => true, 'age' => true, 'now' => true, 'current_date' => true, 'current_time' => true,
        'current_timestamp' => true, 'localtime' => true, 'localtimestamp' => true, 'clock_timestamp' => true,
        'statement_timestamp' => true, 'transaction_timestamp' => true, 'timeofday' => true,
        'make_date' => true, 'make_time' => true, 'make_interval' => true, 'make_timestamp' => true,
        'make_timestamptz' => true, 'justify_days' => true, 'justify_hours' => true,
        'justify_interval' => true, 'isfinite' => true,
        // JSON.
        'json_build_object' => true, 'jsonb_build_object' => true, 'json_build_array' => true,
        'jsonb_build_array' => true, 'to_json' => true, 'to_jsonb' => true, 'row_to_json' => true,
        'array_to_json' => true, 'json_extract_path' => true, 'jsonb_extract_path' => true,
        'json_extract_path_text' => true, 'jsonb_extract_path_text' => true, 'json_array_length' => true,
        'jsonb_array_length' => true, 'json_each' => true, 'jsonb_each' => true, 'json_each_text' => true,
        'jsonb_each_text' => true, 'json_array_elements' => true, 'jsonb_array_elements' => true,
        'json_array_elements_text' => true, 'jsonb_array_elements_text' => true, 'json_object_keys' => true,
        'jsonb_object_keys' => true, 'json_typeof' => true, 'jsonb_typeof' => true, 'jsonb_set' => true,
        'jsonb_insert' => true, 'jsonb_strip_nulls' => true, 'json_strip_nulls' => true, 'jsonb_pretty' => true,
        'jsonb_path_exists' => true, 'jsonb_path_match' => true, 'jsonb_path_query' => true,
        'jsonb_path_query_array' => true, 'jsonb_path_query_first' => true,
        // Full text search.
        'to_tsvector' => true, 'to_tsquery' => true, 'plainto_tsquery' => true, 'phraseto_tsquery' => true,
        'websearch_to_tsquery' => true, 'ts_rank' => true, 'ts_rank_cd' => true, 'ts_headline' => true,
        'setweight' => true,
        // Set-returning and miscellaneous.
        'generate_series' => true, 'generate_subscripts' => true, 'gen_random_uuid' => true, 'pg_sleep' => true,
        'pg_typeof' => true,
        // Transaction-scoped advisory locks: released when the transaction (or statement) ends.
        'pg_advisory_xact_lock' => true, 'pg_advisory_xact_lock_shared' => true,
        'pg_try_advisory_xact_lock' => true, 'pg_try_advisory_xact_lock_shared' => true,
        // Reads of session and catalog information.
        'version' => true, 'current_setting' => true, 'current_database' => true, 'current_schema' => true,
        'current_schemas' => true, 'pg_backend_pid' => true, 'pg_listening_channels' => true,
        'to_regclass' => true, 'to_regtype' => true, 'format_type' => true, 'pg_get_expr' => true,
        'pg_get_indexdef' => true, 'pg_get_constraintdef' => true, 'pg_get_viewdef' => true,
        'pg_get_serial_sequence' => true, 'obj_description' => true, 'col_description' => true,
        'pg_table_size' => true, 'pg_total_relation_size' => true, 'pg_relation_size' => true,
        'pg_indexes_size' => true, 'pg_size_pretty' => true, 'pg_table_is_visible' => true,
        'pg_type_is_visible' => true,
    ];
}
