<?php

use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;
use Illuminate\Database\PostgresConnection;
use Tests\Fledge\database\Stubs\FakeRowResult;

afterEach(fn () => Mockery::close());

it('refuses another fiber inside a pinned transaction with an unmapped LogicException', function () {
    $mockTransaction = Mockery::mock(SqlTransaction::class);
    $mockTransaction->shouldReceive('isActive')->andReturnTrue();
    $mockTransaction->shouldNotReceive('prepare');

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->beginTransaction();

    foreach (['prepare', 'commit', 'rollBack', 'beginTransaction'] as $method) {
        $fiber = new Fiber(function () use ($pdo, $method): ?Throwable {
            try {
                $method === 'prepare' ? $pdo->prepare('SELECT ?') : $pdo->{$method}();
            } catch (Throwable $e) {
                return $e;
            }

            return null;
        });
        $fiber->start();

        expect($fiber->getReturn())->toBeInstanceOf(LogicException::class, $method)
            ->and($fiber->getReturn())->not->toBeInstanceOf(PDOException::class);
    }

    expect($pdo->inTransaction())->toBeTrue();
});

it('converts ? placeholders to $N', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT * FROM users WHERE id = $1 AND name = $2')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('SELECT * FROM users WHERE id = ? AND name = ?');
});

it('preserves ? inside single-quoted strings', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with("SELECT * FROM users WHERE name = 'what?' AND id = \$1")
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare("SELECT * FROM users WHERE name = 'what?' AND id = ?");
});

it('preserves ? inside double-quoted identifiers', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT "col?" FROM users WHERE id = $1')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('SELECT "col?" FROM users WHERE id = ?');
});

it('handles queries without placeholders', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT * FROM users')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('SELECT * FROM users');
});

it('converts many placeholders', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('INSERT INTO t (a, b, c) VALUES ($1, $2, $3)')
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare('INSERT INTO t (a, b, c) VALUES (?, ?, ?)');
});

it('quotes strings with PostgreSQL escaping', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    expect($pdo->quote('hello'))->toBe("'hello'")
        ->and($pdo->quote("it's"))->toBe("'it''s'")
        ->and($pdo->quote("O'Brien"))->toBe("'O''Brien'");
});

function pgPoolWithScs(?string $setting): SqlConnectionPool
{
    $pool = Mockery::mock(SqlConnectionPool::class);
    $expectation = $pool->shouldReceive('query')->with('SHOW standard_conforming_strings');

    $setting === null
        ? $expectation->andThrow(new SqlException('permission denied'))
        : $expectation->andReturn(new FakeRowResult([['standard_conforming_strings' => $setting]]));

    return $pool;
}

it('quotes like pdo_pgsql under standard_conforming_strings on', function () {
    $pdo = new FledgePostgresPdo(pgPoolWithScs('on'));

    expect($pdo->quote('C:\\dir\\file'))->toBe("'C:\\dir\\file'")
        ->and($pdo->quote("a\\'b"))->toBe("'a\\''b'")
        ->and($pdo->quote(''))->toBe("''")
        ->and($pdo->quote('héllo ✓'))->toBe("'héllo ✓'");
});

it('doubles backslashes like libpq under standard_conforming_strings off', function () {
    $pdo = new FledgePostgresPdo(pgPoolWithScs('off'));

    expect($pdo->quote('C:\\dir'))->toBe("'C:\\\\dir'")
        ->and($pdo->quote("a\\'b"))->toBe("'a\\\\''b'");
});

dataset('standard_conforming_strings settings', [
    'on' => ['on', "'\\'' OR 1=1 --'"],
    'off' => ['off', "'\\\\'' OR 1=1 --'"],
    'unknown' => [null, "E'\\\\'' OR 1=1 --'"],
]);

it('neutralises a backslash-quote injection under every setting', function (?string $setting, string $expected) {
    $pdo = new FledgePostgresPdo(pgPoolWithScs($setting));

    $quoted = $pdo->quote("\\' OR 1=1 --");

    expect($quoted)->toBe($expected);

    // Parse the literal the way the server would under the setting in force and
    // check the whole input came back as a single string value.
    $backslashEscapes = $setting !== 'on';
    $body = $setting === null ? substr($quoted, 2, -1) : substr($quoted, 1, -1);
    $value = '';

    for ($i = 0; $i < strlen($body); $i++) {
        if ($body[$i] === "'") {
            expect($body[$i + 1] ?? '')->toBe("'");
            $value .= "'";
            $i++;
        } elseif ($backslashEscapes && $body[$i] === '\\') {
            $value .= $body[++$i];
        } else {
            $value .= $body[$i];
        }
    }

    expect($value)->toBe("\\' OR 1=1 --");
})->with('standard_conforming_strings settings');

it('falls back to a safe E literal when the setting cannot be read, and retries later', function () {
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('query')->with('SHOW standard_conforming_strings')
        ->twice()
        ->andReturnUsing(
            fn () => throw new SqlException('current transaction is aborted'),
            fn () => new FakeRowResult([['standard_conforming_strings' => 'on']]),
        );

    $pdo = new FledgePostgresPdo($pool);

    expect($pdo->quote('a\\b'))->toBe("E'a\\\\b'")
        ->and($pdo->quote('a\\b'))->toBe("'a\\b'")
        ->and($pdo->quote('c\\d'))->toBe("'c\\d'");
});

it('does not consult the server when the value has no backslash', function () {
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldNotReceive('query');

    $pdo = new FledgePostgresPdo($pool);

    expect($pdo->quote("it's"))->toBe("'it''s'")
        ->and($pdo->quote('7', PDO::PARAM_INT))->toBe("'7'");
});

it('quotes PARAM_INT as a string literal like pdo_pgsql', function () {
    $pdo = new FledgePostgresPdo(Mockery::mock(SqlConnectionPool::class));

    expect($pdo->quote('42', PDO::PARAM_INT))->toBe("'42'")
        ->and($pdo->quote('1 OR 1=1', PDO::PARAM_INT))->toBe("'1 OR 1=1'")
        ->and($pdo->quote('1', PDO::PARAM_BOOL))->toBe("'1'");
});

it('quotes PARAM_LOB as a hex bytea literal for each setting', function (?string $setting, string $expected) {
    $pdo = new FledgePostgresPdo(pgPoolWithScs($setting));

    expect($pdo->quote("hi\0'", PDO::PARAM_LOB))->toBe($expected);
})->with([
    'on' => ['on', "'\\x68690027'"],
    'off' => ['off', "'\\\\x68690027'"],
    'unknown' => [null, "E'\\\\x68690027'"],
]);

it('truncates at a NUL byte and rejects invalid UTF-8 like libpq', function () {
    $pdo = new FledgePostgresPdo(Mockery::mock(SqlConnectionPool::class));

    expect($pdo->quote("abc\0def"))->toBe("'abc'")
        ->and($pdo->quote("bad \xC3\x28 byte"))->toBeFalse();
});

it('escapes backslashes correctly in Connection::escape and toRawSql', function () {
    $pdo = new FledgePostgresPdo(pgPoolWithScs('on'));
    $connection = new PostgresConnection(fn () => $pdo, 'test');

    expect($connection->escape('C:\\temp\\new'))->toBe("'C:\\temp\\new'")
        ->and($connection->table('files')->where('path', 'C:\\x')->toRawSql())
        ->toBe('select * from "files" where "path" = \'C:\\x\'');
});

it('reports no transaction once the server ended it, like PQTRANS_UNKNOWN', function () {
    $transaction = Mockery::mock(SqlTransaction::class);
    $transaction->shouldReceive('isActive')->andReturn(true, false);
    $transaction->shouldNotReceive('rollback');

    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('beginTransaction')->once()->andReturn($transaction);

    $pdo = new FledgePostgresPdo($pool);
    $pdo->beginTransaction();

    expect($pdo->inTransaction())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(fn () => $pdo->rollBack())->toThrow(PDOException::class, 'There is no active transaction');
});

it('begins a new transaction after the server ended the pinned one', function () {
    $dead = Mockery::mock(SqlTransaction::class);
    $dead->shouldReceive('isActive')->andReturn(false);
    $fresh = Mockery::mock(SqlTransaction::class);
    $fresh->shouldReceive('isActive')->andReturn(true);

    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('beginTransaction')->twice()->andReturn($dead, $fresh);

    $pdo = new FledgePostgresPdo($pool);

    expect($pdo->beginTransaction())->toBeTrue()
        ->and($pdo->beginTransaction())->toBeTrue()
        ->and($pdo->inTransaction())->toBeTrue();
});

dataset('placeholder conversions', [
    'escaped ?? becomes a literal ?' => [
        'SELECT data ?? ? FROM t WHERE id = ?',
        'SELECT data ? $1 FROM t WHERE id = $2',
    ],
    'jsonb ?| and ?& operators' => [
        'SELECT * FROM t WHERE tags ??| array[?] AND tags ??& array[?]',
        'SELECT * FROM t WHERE tags ?| array[$1] AND tags ?& array[$2]',
    ],
    'three question marks are an escape plus a placeholder' => [
        'SELECT a ??? b',
        'SELECT a ?$1 b',
    ],
    'line comment' => [
        "SELECT 1 -- what? ??\nWHERE id = ?",
        "SELECT 1 -- what? ??\nWHERE id = \$1",
    ],
    'line comment at end of query' => [
        'SELECT ? -- trailing ?',
        'SELECT $1 -- trailing ?',
    ],
    'block comment' => [
        'SELECT /* is it? ?? */ ? /**/ ?',
        'SELECT /* is it? ?? */ $1 /**/ $2',
    ],
    'unterminated block comment is not a comment' => [
        'SELECT ? /* ?',
        'SELECT $1 /* $2',
    ],
    'single minus and slash are text' => [
        'SELECT ? - 1 / ?',
        'SELECT $1 - 1 / $2',
    ],
    'anonymous dollar quote' => [
        'SELECT $$ what? ? $$, ?',
        'SELECT $$ what? ? $$, $1',
    ],
    'tagged dollar quote with nested other tag' => [
        'SELECT $fn$ a ? $$ b ? $x$ c ? $fn$ || ?',
        'SELECT $fn$ a ? $$ b ? $x$ c ? $fn$ || $1',
    ],
    'escaped ?? inside a dollar quote is unescaped like PDO' => [
        'SELECT $$ a ?? b $$',
        'SELECT $$ a ? b $$',
    ],
    'existing $1 is not a dollar quote' => [
        'SELECT $1, ?',
        'SELECT $1, $1',
    ],
    'E string with backslash-escaped quote' => [
        "SELECT E'it\\'s ?' , ?",
        "SELECT E'it\\'s ?' , \$1",
    ],
    'lowercase e string with escaped backslash before the closing quote' => [
        "SELECT e'dir\\\\' , ?",
        "SELECT e'dir\\\\' , \$1",
    ],
    'backslash is not an escape in a plain string' => [
        "SELECT 'C:\\' , ?",
        "SELECT 'C:\\' , \$1",
    ],
    'double-quoted identifier with doubled quote' => [
        'SELECT "a""?" FROM t WHERE x = ?',
        'SELECT "a""?" FROM t WHERE x = $1',
    ],
    'unterminated literal falls back to text' => [
        "SELECT ? 'oops ?",
        "SELECT \$1 'oops \$2",
    ],
    'casts with double colon' => [
        'SELECT ?::jsonb, col::text',
        'SELECT $1::jsonb, col::text',
    ],
]);

it('converts placeholders like PDO pgsql', function (string $query, string $expected) {
    $pdo = new FledgePostgresPdo(Mockery::mock(SqlConnectionPool::class));

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');

    expect($method->invoke($pdo, $query))->toBe($expected);
})->with('placeholder conversions');

it('prepares a whereJsonContainsKey query compiled by the PostgresGrammar', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);
    $connection = new PostgresConnection(fn () => $pdo, 'test');

    $sql = $connection->table('users')
        ->whereJsonContainsKey('options->languages')
        ->where('id', 5)
        ->toSql();

    expect($sql)->toBe('select * from "users" where coalesce(("options")::jsonb ?? \'languages\', false) and "id" = ?');

    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('select * from "users" where coalesce(("options")::jsonb ? \'languages\', false) and "id" = $1')
        ->andReturn(Mockery::mock(SqlStatement::class));

    $pdo->prepare($sql);
});

it('returns pgsql as driver name', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('pgsql');
});

it('caches server version', function () {
    $result = new FakeRowResult([['version' => 'PostgreSQL 16.1 on x86_64']]);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')
        ->once()
        ->with('SELECT version()')
        ->andReturn($result);

    $pdo = new FledgePostgresPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))
        ->toBe('PostgreSQL 16.1 on x86_64');
});

it('preserves ? inside escaped single quotes', function () {
    $mockStmt = Mockery::mock(SqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with("SELECT * FROM t WHERE name = 'it''s a ?' AND id = \$1")
        ->andReturn($mockStmt);

    $pdo = new FledgePostgresPdo($mockPool);
    $pdo->prepare("SELECT * FROM t WHERE name = 'it''s a ?' AND id = ?");
});

it('convertPlaceholders handles empty string', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');

    expect($method->invoke($pdo, ''))->toBe('');
});

it('convertPlaceholders handles consecutive placeholders', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');

    expect($method->invoke($pdo, '(?, ?, ?)'))->toBe('($1, $2, $3)');
});

it('convertPlaceholders passes through query without ? or quotes', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgePostgresPdo($mockPool);

    $method = new ReflectionMethod($pdo, 'convertPlaceholders');
    $query = 'SELECT id, name FROM users ORDER BY id';

    expect($method->invoke($pdo, $query))->toBe($query);
});
