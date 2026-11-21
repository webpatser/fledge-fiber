<?php

use Fledge\Async\Database\Mysql\Internal\MysqlCommandResult;
use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\Mysql\MysqlTransaction;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Fledge\Loop;
use Tests\Fledge\database\Stubs\FakeRowResult;

use function Fledge\Async\async;

afterEach(fn () => Mockery::close());

function pdoQuotePacket(string $payload, int $seq): string
{
    return substr(pack('V', strlen($payload)), 0, 3).chr($seq & 0xff).$payload;
}

/**
 * Runs a scripted MySQL server that answers the handshake and every query with an OK packet carrying
 * the status flags of that connection ($statusFlags, or $statusFlags[n] for the n-th connection), and
 * closes on COM_QUIT. Builds a FledgeMySqlPdo on a real MysqlConnectionPool, hands it and the pool to
 * $client, then closes the pooled connections.
 *
 * @param int|list<int> $statusFlags
 */
function withQuoteServer(int|array $statusFlags, string $charset, Closure $client): mixed
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $watchers = [];
    $accepted = 0;

    $watchers[] = Loop::onReadable($server, static function (string $id, $server) use (&$watchers, &$accepted, $statusFlags): void {
        $conn = stream_socket_accept($server, 0);
        stream_set_blocking($conn, false);
        $statusFlags = is_array($statusFlags) ? $statusFlags[min($accepted, count($statusFlags) - 1)] : $statusFlags;
        $accepted++;

        $capabilities = 0x00000200 | 0x00008000 | 0x00080000; // PROTOCOL_41 | SECURE_CONNECTION | PLUGIN_AUTH
        fwrite($conn, pdoQuotePacket(
            "\x0a8.0.40\0".pack('V', 1).'abcdefgh'."\0".pack('v', $capabilities & 0xffff)."\x21"
            .pack('v', $statusFlags).pack('v', $capabilities >> 16).chr(21).str_repeat("\0", 10)
            ."ijklmnopqrst\0mysql_native_password\0",
            0,
        ));
        $ok = "\x00\x00\x00".pack('v', $statusFlags)."\x00\x00";
        $buffer = '';

        $watchers[] = Loop::onReadable($conn, static function (string $id, $conn) use (&$buffer, $ok): void {
            $chunk = fread($conn, 65536);
            if ($chunk === '' || $chunk === false) {
                Loop::cancel($id);
                fclose($conn);

                return;
            }

            $buffer .= $chunk;

            while (strlen($buffer) >= 4) {
                $length = unpack('V', substr($buffer, 0, 3)."\0")[1];
                if (strlen($buffer) < 4 + $length) {
                    break;
                }

                $seq = ord($buffer[3]);
                $payload = substr($buffer, 4, $length);
                $buffer = substr($buffer, 4 + $length);

                if ($payload === "\x01") {
                    Loop::cancel($id);
                    fclose($conn);

                    return;
                }

                fwrite($conn, pdoQuotePacket($ok, $seq + 1));
            }
        });
    });

    $pool = new MysqlConnectionPool(new MysqlConfig('127.0.0.1', $port, 'app', 'secret', charset: $charset));

    try {
        return async(static fn () => $client(new FledgeMySqlPdo($pool), $pool))->await(new TimeoutCancellation(5));
    } finally {
        while ($pool->getIdleConnectionCount() > 0) {
            async(static fn () => $pool->extractConnection()->close())->await(new TimeoutCancellation(2));
        }
        $pool->close();

        foreach ($watchers as $watcher) {
            Loop::cancel($watcher);
        }
        fclose($server);
    }
}

it('doubles quotes instead of backslash escaping when the server reports NO_BACKSLASH_ESCAPES', function () {
    $quoted = withQuoteServer(0x0202, '', static fn (FledgeMySqlPdo $pdo) => [
        $pdo->quote("' OR 1=1 -- "),
        $pdo->quote('a\\b'),
        $pdo->quote("\\' OR 1=1 -- "),
    ]);

    expect($quoted)->toBe([
        "''' OR 1=1 -- '",
        "'a\\b'",
        "'\\'' OR 1=1 -- '",
    ]);
});

it('backslash escapes like mysqlnd but doubles quotes when the server does not report NO_BACKSLASH_ESCAPES', function () {
    $quoted = withQuoteServer(0x0002, 'utf8mb4', static fn (FledgeMySqlPdo $pdo) => [
        $pdo->quote("it's"),
        $pdo->quote("a\\b\n\"\0\x1a"),
        $pdo->quote('héllo'),
        $pdo->quote("\\' OR 1=1 -- "),
    ]);

    // '' instead of mysqlnd's \' (same value): it stays a quote if the query lands on a NO_BACKSLASH_ESCAPES session.
    expect($quoted)->toBe([
        "'it''s'",
        "'a\\\\b\\n\\\"\\0\\Z'",
        "'héllo'",
        "'\\\\'' OR 1=1 -- '",
    ]);
});

it('doubles quotes and backslashes when the pooled sessions disagree on NO_BACKSLASH_ESCAPES', function () {
    $quoted = withQuoteServer([0x0002, 0x0202], '', static function (FledgeMySqlPdo $pdo, MysqlConnectionPool $pool): array {
        // Two queries in flight at once make the pool open a second connection, which reports the other mode.
        $first = async(static fn () => $pool->query('DO 1'));
        $second = async(static fn () => $pool->query('DO 2'));
        $first->await();
        $second->await();

        return [$pool->getConnectionCount(), $pool->isBackslashEscapingDisabled(), $pdo->quote("a\\' OR 1=1 -- ")];
    });

    expect($quoted)->toBe([2, null, "'a\\\\'' OR 1=1 -- '"]);
});

it('escapes per character of a multibyte connection charset so a lead byte cannot swallow the escape', function (string $charset) {
    $quoted = withQuoteServer(0x0002, $charset, static fn (FledgeMySqlPdo $pdo) => [
        $pdo->quote("\x95' OR 1=1 -- "),
        $pdo->quote("\x95\x5c"),
    ]);

    // 0x95 leads a two byte character in each of these charsets; 0x27 is no valid trail, 0x5C is.
    expect($quoted)->toBe([
        "'\\\x95'' OR 1=1 -- '",
        "'\x95\x5c'",
    ]);
})->with(['gbk', 'gb18030', 'sjis', 'cp932']);

it('keeps a gb18030 four byte character intact', function () {
    $quoted = withQuoteServer(0x0002, 'gb18030', static fn (FledgeMySqlPdo $pdo) => [
        $pdo->quote("\x81\x30\x81\x30' OR 1=1 -- "),
        $pdo->quote("\x81\x30\x81\x30\\"),
    ]);

    expect($quoted)->toBe([
        "'\x81\x30\x81\x30'' OR 1=1 -- '",
        "'\x81\x30\x81\x30\\\\'",
    ]);
});

it('escapes per character of a big5 connection', function () {
    $quoted = withQuoteServer(0x0002, 'big5', static fn (FledgeMySqlPdo $pdo) => [
        $pdo->quote("\xa5' OR 1=1 -- "),
        $pdo->quote("\xa5\x5c"),
    ]);

    expect($quoted)->toBe([
        "'\\\xa5'' OR 1=1 -- '",
        "'\xa5\x5c'",
    ]);
});

it('prepares and returns FledgePdoStatement', function () {
    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->with('SELECT * FROM users WHERE id = ?')
        ->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->prepare('SELECT * FROM users WHERE id = ?'))
        ->toBeInstanceOf(FledgePdoStatement::class);
});

it('exec returns affected row count', function () {
    $result = new MysqlCommandResult(3, 0);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')->once()->andReturn($result);

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->exec('DELETE FROM users WHERE active = 0'))->toBe(3);
});

it('tracks last insert ID from exec', function () {
    $result = new MysqlCommandResult(1, 42);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')->andReturn($result);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->exec("INSERT INTO users (name) VALUES ('test')");

    expect($pdo->lastInsertId())->toBe('42');
});

it('tracks last insert ID from prepared statement execute', function () {
    $result = new MysqlCommandResult(1, 99);

    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockStmt->shouldReceive('execute')
        ->once()
        ->with(['Alice'])
        ->andReturn($result);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('prepare')
        ->once()
        ->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);

    $stmt = $pdo->prepare('INSERT INTO users (name) VALUES (?)');
    $stmt->bindValue(1, 'Alice', PDO::PARAM_STR);
    $stmt->execute();

    expect($pdo->lastInsertId())->toBe('99');
});

it('pins queries to transaction connection', function () {
    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('prepare')
        ->once()
        ->with('INSERT INTO users (name) VALUES (?)')
        ->andReturn($mockStmt);
    $mockTransaction->shouldReceive('commit')->once();

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);
    $mockPool->shouldNotReceive('prepare');

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->beginTransaction())->toBeTrue()
        ->and($pdo->inTransaction())->toBeTrue();

    $pdo->prepare('INSERT INTO users (name) VALUES (?)');

    expect($pdo->commit())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse();
});

it('rolls back transaction', function () {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('rollback')->once();

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->andReturn($mockTransaction);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();

    expect($pdo->rollBack())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse();
});

it('throws like PDO on rollback without transaction', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect(fn () => $pdo->rollBack())->toThrow(PDOException::class, 'There is no active transaction');
});

it('caches server version from SELECT VERSION()', function () {
    $result = new FakeRowResult([['VERSION()' => '8.0.35']]);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('query')
        ->once()
        ->with('SELECT VERSION()')
        ->andReturn($result);

    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toBe('8.0.35')
        ->and($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toBe('8.0.35'); // cached
});

it('returns mysql as driver name', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('mysql');
});

it('quotes strings with MySQL escaping', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->quote('hello'))->toBe("'hello'")
        ->and($pdo->quote("it's"))->toBe("'it''s'")
        ->and($pdo->quote("line\nbreak"))->toBe("'line\\nbreak'");
});

it('quotes integers with PARAM_INT', function () {
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $pdo = new FledgeMySqlPdo($mockPool);

    expect($pdo->quote('42', PDO::PARAM_INT))->toBe('42')
        ->and($pdo->quote('not_a_number', PDO::PARAM_INT))->toBe('0');
});

it('refuses queries from another fiber while a transaction is pinned', function () {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldNotReceive('prepare');
    $mockTransaction->shouldNotReceive('query');

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();

    $attempts = [
        'prepare' => fn () => $pdo->prepare('SELECT 1'),
        'exec' => fn () => $pdo->exec('DELETE FROM users'),
        'beginTransaction' => fn () => $pdo->beginTransaction(),
        'commit' => fn () => $pdo->commit(),
        'rollBack' => fn () => $pdo->rollBack(),
    ];

    foreach ($attempts as $method => $attempt) {
        $fiber = new Fiber(function () use ($attempt): ?Throwable {
            try {
                $attempt();
            } catch (Throwable $e) {
                return $e;
            }

            return null;
        });
        $fiber->start();

        expect($fiber->getReturn())
            ->toBeInstanceOf(LogicException::class, $method)
            ->and($fiber->getReturn()->getMessage())->toContain('Another fiber holds an open transaction');
    }

    expect($pdo->inTransaction())->toBeTrue();
});

it('refuses main context queries while a fiber holds the transaction', function () {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('commit')->once();
    $mockTransaction->shouldNotReceive('query');

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);

    $pdo = new FledgeMySqlPdo($mockPool);

    $fiber = new Fiber(function () use ($pdo): void {
        $pdo->beginTransaction();
        Fiber::suspend();
        $pdo->commit();
    });
    $fiber->start();

    expect(fn () => $pdo->exec('DELETE FROM users'))->toThrow(LogicException::class, 'Another fiber holds an open transaction');

    $fiber->resume();

    expect($fiber->isTerminated())->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse();
});

it('lets the owning fiber use its own transaction', function () {
    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockStmt->shouldReceive('execute')->once()->andReturn(new MysqlCommandResult(1, 7));

    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('prepare')->once()->with('INSERT INTO users (name) VALUES (?)')->andReturn($mockStmt);
    $mockTransaction->shouldReceive('query')->once()->with('DELETE FROM users')->andReturn(new MysqlCommandResult(2, 0));
    $mockTransaction->shouldReceive('commit')->once();

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);
    $mockPool->shouldNotReceive('prepare');
    $mockPool->shouldNotReceive('query');

    $pdo = new FledgeMySqlPdo($mockPool);

    $fiber = new Fiber(function () use ($pdo): array {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO users (name) VALUES (?)');
        $stmt->bindValue(1, 'Alice');
        $stmt->execute();
        $deleted = $pdo->exec('DELETE FROM users');
        $pdo->commit();

        return [$deleted, $pdo->lastInsertId()];
    });
    $fiber->start();

    expect($fiber->getReturn())->toBe([2, '7']);
});

it('refuses executing a statement prepared inside the transaction from another fiber', function () {
    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockStmt->shouldNotReceive('execute');

    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('prepare')->once()->andReturn($mockStmt);

    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM users');

    $fiber = new Fiber(function () use ($stmt): ?Throwable {
        try {
            $stmt->execute();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    });
    $fiber->start();

    expect($fiber->getReturn())->toBeInstanceOf(LogicException::class);
});

it('lets another fiber query again once the transaction ends', function (string $end) {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive($end === 'commit' ? 'commit' : 'rollback')->once();

    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->once()->andReturn($mockTransaction);
    $mockPool->shouldReceive('prepare')->once()->with('SELECT 1')->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();
    $pdo->{$end}();

    $fiber = new Fiber(fn () => $pdo->prepare('SELECT 1'));
    $fiber->start();

    expect($fiber->getReturn())->toBeInstanceOf(FledgePdoStatement::class);
})->with(['commit', 'rollBack']);

it('uses pool after commit', function () {
    $mockTransaction = Mockery::mock(MysqlTransaction::class);
    $mockTransaction->shouldReceive('commit')->once();

    $mockStmt = Mockery::mock(MysqlStatement::class);
    $mockPool = Mockery::mock(SqlConnectionPool::class);
    $mockPool->shouldReceive('beginTransaction')->andReturn($mockTransaction);
    $mockPool->shouldReceive('prepare')->once()->with('SELECT 1')->andReturn($mockStmt);

    $pdo = new FledgeMySqlPdo($mockPool);
    $pdo->beginTransaction();
    $pdo->commit();

    $pdo->prepare('SELECT 1');
});
