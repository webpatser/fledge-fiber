<?php

use function Fledge\Async\Database\Postgres\Internal\parseNamedParams;

dataset('parsed statements', [
    'unnamed placeholders' => [
        'SELECT ? FROM t WHERE id = ?',
        'SELECT $1 FROM t WHERE id = $2',
        [0, 1],
    ],
    'named placeholders and casts' => [
        'SELECT :a::int, :b, col::text',
        'SELECT $1::int, $2, col::text',
        ['a', 'b'],
    ],
    'numbered placeholders stay in place' => [
        'SELECT $1, $2, $1',
        'SELECT $1, $2, $3',
        [0, 1, 0],
    ],
    'operator-flanked ? is not a placeholder' => [
        'SELECT a ?| b, a ?& b, a @? b',
        'SELECT a ?| b, a ?& b, a @? b',
        [],
    ],
    'escaped ?? is a literal ? next to numbered placeholders' => [
        "SELECT data ?? 'a' AND id = \$1",
        "SELECT data ? 'a' AND id = \$1",
        [0],
    ],
    'escaped ??| and ??&' => [
        'SELECT tags ??| array[$1] AND tags ??& array[$2]',
        'SELECT tags ?| array[$1] AND tags ?& array[$2]',
        [0, 1],
    ],
    'escaped ?? followed by a numbered placeholder' => [
        'SELECT a ??$1 b',
        'SELECT a ?$1 b',
        [0],
    ],
    'single-quoted string' => [
        "SELECT '? :x \$9 ??', ?",
        "SELECT '? :x \$9 ??', \$1",
        [0],
    ],
    'backslash is not an escape in a plain string' => [
        "SELECT 'C:\\', ?",
        "SELECT 'C:\\', \$1",
        [0],
    ],
    'E string with a backslash-escaped quote' => [
        "SELECT E'it\\'s ? :x', ?",
        "SELECT E'it\\'s ? :x', \$1",
        [0],
    ],
    'double-quoted identifier' => [
        'SELECT "a""?:x" FROM t WHERE x = ?',
        'SELECT "a""?:x" FROM t WHERE x = $1',
        [0],
    ],
    'line comment' => [
        "SELECT \$1 -- what about :name, \$2 or ? ??\nWHERE x = \$2",
        "SELECT \$1 -- what about :name, \$2 or ? ??\nWHERE x = \$2",
        [0, 1],
    ],
    'block comment' => [
        'SELECT $1 /* :name ? $2 ?? */, $2',
        'SELECT $1 /* :name ? $2 ?? */, $2',
        [0, 1],
    ],
    'anonymous dollar quote' => [
        'SELECT $$ is it? :name $1 ?? $$, $1',
        'SELECT $$ is it? :name $1 ?? $$, $1',
        [0],
    ],
    'tagged dollar quote with a nested other tag' => [
        'SELECT $fn$ a ? $$ :b $x$ $1 $fn$ || ?',
        'SELECT $fn$ a ? $$ :b $x$ $1 $fn$ || $1',
        [0],
    ],
]);

it('parses placeholders outside literals, comments and dollar quotes', function (string $sql, string $expected, array $names) {
    expect(parseNamedParams($sql, $parsed))->toBe($expected)
        ->and($parsed)->toBe($names);
})->with('parsed statements');

it('still rejects unnamed placeholders mixed with numbered ones', function () {
    parseNamedParams('SELECT ?, $1', $names);
})->throws(Error::class, 'Cannot mix unnamed (? placeholders) with numbered parameters');
