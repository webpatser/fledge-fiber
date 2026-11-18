<?php

use Fledge\Async\DeferredFuture;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Illuminate\Database\Connection;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Session pinning of the fledge-mysql driver: every statement one fiber (or the
 * main context) runs through a FledgePdo goes to one pooled connection, as with
 * pdo_mysql, where one PDO is one connection.
 */
function sessionPinningConnection(int $poolSize, array $extra = []): Connection
{
    $config = $extra + ['pool_size' => $poolSize] + mysqlConfig();

    return new FledgeMySqlConnection(
        (new FledgeMySqlConnector)->connect($config),
        $config['database'],
        '',
        $config + ['name' => 'fledge-mysql-pinning', 'driver' => 'fledge-mysql'],
    );
}

/**
 * Open $count physical connections at once, so the pool has more than one idle
 * connection to choose from afterwards.
 */
function warmSessionPinningPool(Connection $connection, int $count): void
{
    await(array_map(
        fn () => async(fn () => $connection->select('SELECT SLEEP(0.05)')),
        range(1, $count),
    ));
}

/**
 * Run $work in a fiber and wait for it, failing instead of hanging on a deadlock.
 */
function inPinningFiber(Closure $work, float $timeout = 10): mixed
{
    return async($work)->await(new TimeoutCancellation($timeout, 'Fiber did not finish: deadlock?'));
}

uses()->beforeEach(function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
    }

    $this->connections = [];
})->afterEach(function () {
    foreach ($this->connections ?? [] as $connection) {
        $connection->getPdo()?->close();
    }
});

it('keeps a session variable across two statements in one fiber', function () {
    $this->connections[] = $connection = sessionPinningConnection(4);
    warmSessionPinningPool($connection, 3);

    [$value, $first, $second] = inPinningFiber(function () use ($connection) {
        $first = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $connection->statement('SET @fledge_pin = 42');
        $value = $connection->selectOne('SELECT @fledge_pin AS v')->v;
        $second = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;

        return [$value, $first, $second];
    });

    expect($value)->toBe(42)
        ->and($second)->toBe($first);
});

it('keeps session state across statements in the main context', function () {
    $this->connections[] = $connection = sessionPinningConnection(4);
    warmSessionPinningPool($connection, 3);

    $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
    expect((int) $connection->selectOne('SELECT @@foreign_key_checks AS v')->v)->toBe(0);

    $connection->statement('CREATE TEMPORARY TABLE _fledge_pin_tmp (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))');
    $connection->insert('INSERT INTO _fledge_pin_tmp (name) VALUES (?)', ['a']);
    $connection->insert('INSERT INTO _fledge_pin_tmp (name) VALUES (?)', ['b']);

    expect($connection->selectOne('SELECT COUNT(*) AS c FROM _fledge_pin_tmp')->c)->toBe(2)
        ->and($connection->selectOne('SELECT LAST_INSERT_ID() AS id')->id)->toBe(2);

    expect($connection->selectOne("SELECT GET_LOCK('_fledge_pin_lock', 0) AS l")->l)->toBe(1)
        ->and($connection->selectOne("SELECT RELEASE_LOCK('_fledge_pin_lock') AS l")->l)->toBe(1);
});

it('gives two concurrent fibers their own session', function () {
    $this->connections[] = $connection = sessionPinningConnection(4);
    warmSessionPinningPool($connection, 3);

    $run = fn (string $value) => async(function () use ($connection, $value) {
        $connection->statement("SET @fledge_pin = '{$value}'");
        $first = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        delay(0.05);
        $seen = $connection->selectOne('SELECT @fledge_pin AS v')->v;
        $second = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;

        return [$seen, $first, $second];
    });

    [[$seenA, $firstA, $secondA], [$seenB, $firstB, $secondB]] = await([$run('a'), $run('b')], new TimeoutCancellation(10));

    expect($seenA)->toBe('a')
        ->and($seenB)->toBe('b')
        ->and($secondA)->toBe($firstA)
        ->and($secondB)->toBe($firstB)
        ->and($firstA)->not->toBe($firstB);
});

it('releases the pin when the fiber ends, also on an exception', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    expect(inPinningFiber(fn () => $connection->selectOne('SELECT 1 AS v')->v))->toBe(1);

    expect(fn () => inPinningFiber(function () use ($connection) {
        $connection->select('SELECT 1');

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect(inPinningFiber(fn () => $connection->selectOne('SELECT 2 AS v')->v))->toBe(2);
});

it('releases the pin of a fiber that is abandoned mid-scope', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    // The fiber pins the connection, then waits on a future nobody completes;
    // dropping that future leaves the suspended fiber unreachable.
    $never = new DeferredFuture;
    $started = new DeferredFuture;

    async(function () use ($connection, $never, $started) {
        $connection->select('SELECT 1');
        $started->complete();
        $never->getFuture()->await();
    })->ignore();

    $started->getFuture()->await(new TimeoutCancellation(10));
    unset($never);
    delay(0.01);
    gc_collect_cycles();

    expect(inPinningFiber(fn () => $connection->selectOne('SELECT 3 AS v')->v))->toBe(3);
});

it('lets more fibers than connections take turns without deadlock', function () {
    $this->connections[] = $connection = sessionPinningConnection(2);

    $futures = array_map(fn (int $i) => async(function () use ($connection, $i) {
        $connection->statement("SET @fledge_pin = {$i}");
        delay(0.01);

        return $connection->selectOne('SELECT @fledge_pin AS v')->v;
    }), range(1, 6));

    $seen = await($futures, new TimeoutCancellation(10));
    ksort($seen);

    expect($seen)->toBe(range(1, 6));
});

it('runs a query inside a cursor loop on the pinned connection', function () {
    $this->connections[] = $connection = sessionPinningConnection(2);

    $seen = inPinningFiber(function () use ($connection) {
        $seen = [];

        foreach ($connection->cursor('SELECT 1 AS v UNION ALL SELECT 2 UNION ALL SELECT 3') as $row) {
            $seen[] = [$row->v, $connection->selectOne('SELECT ? + 10 AS w', [$row->v])->w];
        }

        return $seen;
    });

    expect($seen)->toBe([[1, 11], [2, 12], [3, 13]]);
});

it('starts a new transaction on the pinned connection after a failed one', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $result = inPinningFiber(function () use ($connection) {
        try {
            $connection->transaction(fn () => $connection->select('SELECT * FROM _fledge_pin_missing_table'));
        } catch (Illuminate\Database\QueryException) {
            // expected
        }

        return $connection->transaction(fn () => $connection->selectOne('SELECT 5 AS v')->v);
    });

    expect($result)->toBe(5);
});

it('resets the session before the next fiber gets the same connection', function () {
    $this->connections[] = $connection = sessionPinningConnection(1, ['timezone' => '+03:00']);

    $session = fn () => $connection->selectOne(
        "SELECT CONNECTION_ID() AS id, @fledge_pin AS v, @@session.sql_mode AS mode, @@session.time_zone AS tz, IS_USED_LOCK('_fledge_pin_reset') AS lock_owner"
    );

    $before = inPinningFiber($session);

    inPinningFiber(function () use ($connection) {
        $connection->statement('SET @fledge_pin = 1');
        $connection->statement('CREATE TEMPORARY TABLE _fledge_pin_reset_tmp (id INT)');
        $connection->selectOne("SELECT GET_LOCK('_fledge_pin_reset', 0) AS l");
        $connection->statement("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $connection->statement("SET time_zone = '+00:00'");
    });

    [$after, $tempTable] = inPinningFiber(function () use ($connection, $session) {
        $tables = $connection->select("SHOW TABLES LIKE '_fledge_pin_reset_tmp'");

        try {
            $connection->select('SELECT * FROM _fledge_pin_reset_tmp');
            $temp = true;
        } catch (Illuminate\Database\QueryException) {
            $temp = false;
        }

        return [$session(), $temp || $tables !== []];
    });

    expect($after->id)->toBe($before->id)
        ->and($after->v)->toBeNull()
        ->and($after->lock_owner)->toBeNull()
        ->and($after->mode)->toBe($before->mode)
        ->and($after->tz)->toBe('+03:00')
        ->and($tempTable)->toBeFalse();
});

it('prepares a statement again after its pinned connection was reset', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);
    $pdo = $connection->getPdo();

    $statement = inPinningFiber(function () use ($connection, $pdo) {
        // A dirty session, so the release resets the connection (a clean one goes back as it is).
        $connection->unprepared('SET @fledge_pin = 1');
        $statement = $pdo->prepare('SELECT ? + 1 AS v');
        $statement->execute([1]);

        return $statement;
    });

    // The fiber ended: its connection was reset, which frees the server-side statement id.
    $value = inPinningFiber(function () use ($statement) {
        $statement->execute([41]);

        return $statement->fetch(PDO::FETCH_ASSOC)['v'];
    });

    expect($value)->toBe(42)
        ->and($pdo->sessionResetRoundTrips())->toBe(2)
        ->and($pdo->serverPrepareCount())->toBe(2);
});

it('keeps a statement prepared in a transaction usable after the commit, like pdo_mysql', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $value = inPinningFiber(function () use ($connection) {
        $pdo = $connection->getPdo();
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT ? * 2 AS v');
        $statement->execute([1]);
        $pdo->commit();

        $statement->execute([21]);

        return $statement->fetch(PDO::FETCH_ASSOC)['v'];
    });

    expect($value)->toBe(42);
});

it('lets tasks query after the main context queried, with a pool of one', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    // A controller under php-fpm or artisan serve runs in the main context.
    expect($connection->selectOne('SELECT 1 AS v')->v)->toBe(1);

    $tasks = await([
        async(fn () => $connection->selectOne('SELECT 2 AS v')->v),
        async(fn () => $connection->selectOne('SELECT 3 AS v')->v),
    ], new TimeoutCancellation(10));
    ksort($tasks);

    expect($tasks)->toBe([2, 3])
        ->and($connection->selectOne('SELECT 4 AS v')->v)->toBe(4);
});

it('classifies only plain statements as leaving the session unchanged', function (string $sql, bool $clean) {
    expect(Fledge\Fiber\Database\Pdo\FledgePdo::leavesSessionUnchanged($sql))->toBe($clean);
})->with([
    ['select * from `users` where `id` = ? limit 1', true],
    ['select count(*) as aggregate from `users`', true],
    ["insert into `users` (`name`) values ('a(b)')", true],
    ["update `users` set `name` = 'x; set names latin1' where `id` = ?", true],
    ['delete from `users` where `id` in (?, ?)', true],
    ["show tables like 'users'", true],
    ['explain select * from `users`', true],
    ['describe `users`', true],
    ['select 1;', true],
    ['select * from `users` force index (`users_email_index`) where lower(`email`) = ?', true],
    ['SET FOREIGN_KEY_CHECKS=0', false],
    ['select @x := 1', false],
    ["select GET_LOCK('a', 0)", false],
    ["select RELEASE_LOCK('a')", false],
    ["select IS_FREE_LOCK('a')", false],
    ['select my_stored_function(1)', false],
    ['select mydb.fn(1)', false],
    ['select `mydb`.`lower`(1)', false],
    ['select mydb.count(1)', false],
    ['create temporary table t (id int)', false],
    ['select last_insert_id()', false],
    ['insert into t (id) values (last_insert_id(5))', false],
    ['update t set id = last_insert_id(id + 1)', false],
    ['select 1; set names latin1', false],
    ['select 1; use x', false],
    ['select 1; lock tables t write', false],
    ['select 1 into @x', false],
    ["select * from t into outfile '/tmp/x'", false],
    ['select /*!50000 sleep(1) */ 1', false],
    ['use other_db', false],
    ['lock tables t write', false],
    ['call proc()', false],
    ['select sql_calc_found_rows * from t', false],
    ['handler t open', false],
]);

it('lets a fiber wait on a querying child fiber with a pool of one', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $value = inPinningFiber(function () use ($connection) {
        $connection->select('SELECT 1');

        return async(fn () => $connection->selectOne('SELECT 2 AS v')->v)
            ->await(new TimeoutCancellation(5, 'Child fiber did not get a connection: deadlock'));
    });

    expect($value)->toBe(2);
});

it('does not let fibers idling with a clean session starve a new query', function () {
    $this->connections[] = $connection = sessionPinningConnection(2);

    $hold = new DeferredFuture;
    $started = [];
    $idlers = [];

    foreach (range(1, 3) as $i) {
        $started[$i] = new DeferredFuture;
        $ready = $started[$i];
        $idlers[] = async(function () use ($connection, $hold, $ready, $i) {
            $connection->select('SELECT ? AS v', [$i]);
            $ready->complete();
            $hold->getFuture()->await();
        });
    }

    await(array_map(fn (DeferredFuture $d) => $d->getFuture(), $started), new TimeoutCancellation(5, 'An idle fiber could not run its first query'));

    expect(inPinningFiber(fn () => $connection->selectOne('SELECT 9 AS v')->v, 5))->toBe(9);

    $hold->complete();
    await($idlers, new TimeoutCancellation(5));
});

it('keeps the connection of a fiber that changed its session while others take turns', function () {
    $this->connections[] = $connection = sessionPinningConnection(2);

    $changed = async(function () use ($connection) {
        $connection->unprepared('SET @fledge_pin = 1');
        $first = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        delay(0.1);

        return [$connection->selectOne('SELECT @fledge_pin AS v')->v, $first, $connection->selectOne('SELECT CONNECTION_ID() AS id')->id];
    });

    $others = array_map(fn (int $i) => async(fn () => $connection->selectOne('SELECT ? AS v', [$i])->v), range(1, 4));

    [$value, $first, $second] = $changed->await(new TimeoutCancellation(5));
    await($others, new TimeoutCancellation(5));

    expect($value)->toBe(1)->and($second)->toBe($first);
});

it('selects the configured database again when a released connection is reset', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);
    $database = mysqlConfig()['database'];

    $other = inPinningFiber(function () use ($connection) {
        // The test user may not create schemas; information_schema is a second one every user can use.
        $connection->unprepared('USE information_schema');

        return $connection->selectOne('SELECT DATABASE() AS db')->db;
    });

    $after = inPinningFiber(fn () => $connection->selectOne('SELECT DATABASE() AS db')->db);

    expect($other)->toBe('information_schema')
        ->and($after)->toBe($database);
});

it('resets only a released connection whose session changed, in two round trips', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);
    $pdo = $connection->getPdo();

    inPinningFiber(fn () => $connection->select('SELECT 1'));
    inPinningFiber(fn () => $connection->select('SELECT 2'));
    $clean = $pdo->sessionResetRoundTrips();

    inPinningFiber(fn () => $connection->unprepared('SET @fledge_pin = 1'));
    // The next lease waits until the released connection was reset.
    inPinningFiber(fn () => $connection->select('SELECT 3'));

    expect($clean)->toBe(0)
        ->and($pdo->sessionResetRoundTrips())->toBe(2);
});

it('buffers results by default, like pdo_mysql', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $read = inPinningFiber(function () use ($connection) {
        $statement = $connection->getPdo()->prepare('SELECT seq FROM (WITH RECURSIVE s (seq) AS (SELECT 1 UNION ALL SELECT seq + 1 FROM s WHERE seq < 200) SELECT seq FROM s) t');
        $statement->execute();
        $statement->fetch(PDO::FETCH_ASSOC);

        return $statement->driverRowsRead();
    });

    expect($read)->toBe(200);
});

it('streams rows when buffered queries are off, and refuses a second query until they are read', function () {
    $this->connections[] = $connection = sessionPinningConnection(1, ['options' => [Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false]]);

    [$read, $error, $rest, $after] = inPinningFiber(function () use ($connection) {
        $pdo = $connection->getPdo();
        $statement = $pdo->prepare('SELECT seq FROM (WITH RECURSIVE s (seq) AS (SELECT 1 UNION ALL SELECT seq + 1 FROM s WHERE seq < 200) SELECT seq FROM s) t');
        $statement->execute();
        $statement->fetch(PDO::FETCH_ASSOC);
        $read = $statement->driverRowsRead();

        try {
            $pdo->prepare('SELECT 1');
            $error = null;
        } catch (PDOException $e) {
            $error = $e;
        }

        $rest = count($statement->fetchAll(PDO::FETCH_ASSOC));

        return [$read, $error, $rest, $connection->selectOne('SELECT 5 AS v')->v];
    });

    expect($read)->toBe(1)
        ->and($error)->toBeInstanceOf(PDOException::class)
        ->and($error->getCode())->toBe('HY000')
        ->and($error->errorInfo[1])->toBe(2014)
        ->and($error->getMessage())->toContain('Cannot execute queries while other unbuffered queries are active')
        ->and($rest)->toBe(199)
        ->and($after)->toBe(5);
});

it('reports the buffered query attribute', function () {
    $this->connections[] = $buffered = sessionPinningConnection(1);
    $this->connections[] = $unbuffered = sessionPinningConnection(1, ['options' => [Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false]]);

    expect($buffered->getPdo()->getAttribute(Pdo\Mysql::ATTR_USE_BUFFERED_QUERY))->toBeTrue()
        ->and($unbuffered->getPdo()->getAttribute(Pdo\Mysql::ATTR_USE_BUFFERED_QUERY))->toBeFalse();
});

it('runs a transaction on the pinned connection with a pool of one', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $value = inPinningFiber(function () use ($connection) {
        $connection->statement('SET @fledge_pin = 7');

        return $connection->transaction(fn () => $connection->selectOne('SELECT @fledge_pin AS v')->v);
    });

    expect($value)->toBe(7);
});

it('prepares each statement once under a saturated pool', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $values = await([
        async(fn () => $connection->selectOne('SELECT ? AS v', [1])->v),
        async(fn () => $connection->selectOne('SELECT ? AS v', [2])->v),
    ], new TimeoutCancellation(10));
    ksort($values);

    expect($values)->toBe([1, 2])
        ->and($connection->getPdo()->serverPrepareCount())->toBe(2);
});

/**
 * Run $work with a real table _fledge_pin_ids (auto-increment id, name), made and dropped on a
 * connection of its own, so the pins under test stay clean.
 */
function withPinIdsTable(Closure $work): mixed
{
    $setup = sessionPinningConnection(1);
    $setup->statement('DROP TABLE IF EXISTS _fledge_pin_ids');
    $setup->statement('CREATE TABLE _fledge_pin_ids (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))');

    try {
        return $work();
    } finally {
        $setup->statement('DROP TABLE IF EXISTS _fledge_pin_ids');
        $setup->getPdo()->close();
    }
}

it('keeps lastInsertId per fiber when another fiber inserts while it is suspended', function () {
    $this->connections[] = $connection = sessionPinningConnection(2);

    [$a, $b] = withPinIdsTable(function () use ($connection) {
        $run = fn (string $name) => async(function () use ($connection, $name) {
            $connection->insert('INSERT INTO _fledge_pin_ids (name) VALUES (?)', [$name]);
            // A query listener doing I/O: the other fiber inserts meanwhile.
            delay(0.05);
            $id = $connection->getPdo()->lastInsertId();

            return [$id, $connection->selectOne('SELECT name FROM _fledge_pin_ids WHERE id = ?', [$id])->name];
        });

        return await([$run('a'), $run('b')], new TimeoutCancellation(10));
    });

    expect($a[1])->toBe('a')
        ->and($b[1])->toBe('b')
        ->and($a[0])->not->toBe($b[0]);
});

it('reads its own LAST_INSERT_ID() right after an insert under a saturated pool', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);

    $seen = withPinIdsTable(function () use ($connection) {
        $run = fn (string $name) => async(function () use ($connection, $name) {
            $connection->insert('INSERT INTO _fledge_pin_ids (name) VALUES (?)', [$name]);
            $id = (int) $connection->getPdo()->lastInsertId();
            $sql = (int) $connection->selectOne('SELECT LAST_INSERT_ID() AS id')->id;

            return [$id, $sql, $connection->selectOne('SELECT name FROM _fledge_pin_ids WHERE id = ?', [$sql])->name];
        });

        return await([$run('a'), $run('b')], new TimeoutCancellation(10));
    });

    expect($seen[0][2])->toBe('a')
        ->and($seen[1][2])->toBe('b')
        ->and($seen[0][1])->toBe($seen[0][0])
        ->and($seen[1][1])->toBe($seen[1][0]);
});

it('begins a transaction at the session isolation level, like pdo_mysql', function () {
    $this->connections[] = $fresh = sessionPinningConnection(1);
    $default = $fresh->selectOne('SELECT @@transaction_isolation AS l')->l;
    $this->connections[] = $connection = sessionPinningConnection(1);

    [$inside, $after] = inPinningFiber(function () use ($connection) {
        $inside = $connection->transaction(fn () => $connection->selectOne('SELECT @@transaction_isolation AS l')->l);

        return [$inside, $connection->selectOne('SELECT @@transaction_isolation AS l')->l];
    });

    expect($inside)->toBe($default)
        ->and($after)->toBe($default);
});

it('does not leak a transaction isolation level to the next fiber on the connection', function () {
    $this->connections[] = $fresh = sessionPinningConnection(1);
    $default = $fresh->selectOne('SELECT @@transaction_isolation AS l')->l;
    $this->connections[] = $connection = sessionPinningConnection(1);

    // Only clean statements, so the connection goes to the next fiber without a reset.
    inPinningFiber(fn () => $connection->transaction(fn () => $connection->select('SELECT 1')));
    $next = inPinningFiber(fn () => $connection->selectOne('SELECT @@transaction_isolation AS l')->l);

    expect($next)->toBe($default)
        ->and($connection->getPdo()->sessionResetRoundTrips())->toBe(0);
});

it('keeps a configured isolation level inside and after transactions', function () {
    $this->connections[] = $connection = sessionPinningConnection(1, ['isolation_level' => 'SERIALIZABLE']);

    [$inside, $after] = inPinningFiber(function () use ($connection) {
        $inside = $connection->transaction(fn () => $connection->selectOne('SELECT @@transaction_isolation AS l')->l);

        return [$inside, $connection->selectOne('SELECT @@transaction_isolation AS l')->l];
    });

    // The fiber's session was dirty: the reset applies the configured level again.
    $next = inPinningFiber(fn () => $connection->transaction(fn () => $connection->selectOne('SELECT @@transaction_isolation AS l')->l));

    expect($inside)->toBe('SERIALIZABLE')
        ->and($after)->toBe('SERIALIZABLE')
        ->and($next)->toBe('SERIALIZABLE');
});

it('still sends an isolation level the async pool API was given', function () {
    $config = mysqlConfig();
    $pool = new Fledge\Async\Database\Mysql\MysqlConnectionPool(new Fledge\Async\Database\Mysql\MysqlConfig(
        host: $config['host'],
        port: $config['port'],
        user: $config['username'],
        password: $config['password'],
        database: $config['database'],
    ), 1);

    try {
        $pool->setTransactionIsolation(Fledge\Async\Database\SqlTransactionIsolationLevel::Serializable);
        $transaction = $pool->beginTransaction();
        $level = $transaction->query('SELECT @@transaction_isolation AS l')->fetchRow()['l'];
        $transaction->rollback();
    } finally {
        $pool->close();
    }

    expect($level)->toBe('SERIALIZABLE');
});

it('resets a dirty pin when its fiber ends', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);
    $pdo = $connection->getPdo();

    $first = inPinningFiber(function () use ($connection) {
        $connection->unprepared('SET @fledge_pin = 1');

        return $connection->selectOne('SELECT CONNECTION_ID() AS id, @fledge_pin AS v');
    });

    // Read inside the next fiber: its own (dirty) release is reset later.
    [$after, $resets] = inPinningFiber(fn () => [$connection->selectOne('SELECT CONNECTION_ID() AS id, @fledge_pin AS v'), $pdo->sessionResetRoundTrips()]);

    expect($first->v)->toBe(1)
        ->and($after->id)->toBe($first->id)
        ->and($after->v)->toBeNull()
        ->and($resets)->toBe(2);
});

it('resets a dirty pin when its fiber is destroyed mid-scope', function () {
    $this->connections[] = $connection = sessionPinningConnection(1);
    $pdo = $connection->getPdo();
    $started = new DeferredFuture;

    // A fiber nobody resumes again: destroying it ends its scope without returning.
    $fiber = new Fiber(function () use ($connection, $started) {
        $connection->unprepared('SET @fledge_pin = 1');
        $started->complete($connection->selectOne('SELECT CONNECTION_ID() AS id')->id);
        Fiber::suspend();
    });
    $fiber->start();
    $id = $started->getFuture()->await(new TimeoutCancellation(10));

    $fiber = null;

    [$after, $resets] = inPinningFiber(fn () => [$connection->selectOne('SELECT CONNECTION_ID() AS id, @fledge_pin AS v'), $pdo->sessionResetRoundTrips()]);

    expect($after->id)->toBe($id)
        ->and($after->v)->toBeNull()
        ->and($resets)->toBe(2);
});

/**
 * A TCP proxy in front of the MySQL test server that forwards every byte, except that it answers
 * COM_RESET_CONNECTION itself with error 1047 ('unsupported'), or forwards the reset and answers the
 * query after it (the session re-init) with an error ('fail-init'). Its watchers are unreferenced and
 * it closes a link when either side does, so it cannot keep a test alive.
 */
final class SessionResetInjectingProxy
{
    public int $accepted = 0;

    public int $resets = 0;

    public int $failedInits = 0;

    public readonly int $port;

    /** @var resource */
    private $listener;

    private string $acceptWatcher;

    /** @var list<Closure():void> */
    private array $closers = [];

    public function __construct(private readonly string $mode)
    {
        $this->listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($this->listener, false), ':'), 1);

        $this->acceptWatcher = Revolt\EventLoop::unreference(Revolt\EventLoop::onReadable($this->listener, function (): void {
            $client = @stream_socket_accept($this->listener, 0);

            if ($client !== false) {
                $this->link($client);
            }
        }));
    }

    public function stop(): void
    {
        Revolt\EventLoop::cancel($this->acceptWatcher);

        foreach ($this->closers as $close) {
            $close();
        }

        fclose($this->listener);
    }

    /**
     * @param  resource  $client
     */
    private function link($client): void
    {
        $this->accepted++;
        $config = mysqlConfig();
        $server = stream_socket_client("tcp://{$config['host']}:{$config['port']}", $errno, $error, 5);
        stream_set_read_buffer($client, 0);
        stream_set_read_buffer($server, 0);
        $buffer = '';
        $armed = false;
        $watchers = [];

        $close = function () use (&$watchers, $client, $server): void {
            foreach ($watchers as $watcher) {
                Revolt\EventLoop::cancel($watcher);
            }

            $watchers = [];

            foreach ([$client, $server] as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        };
        $this->closers[] = $close;

        $watchers[] = Revolt\EventLoop::unreference(Revolt\EventLoop::onReadable($server, function () use ($server, $client, $close): void {
            $data = fread($server, 65536);

            if ($data === '' || $data === false) {
                $close();

                return;
            }

            fwrite($client, $data);
        }));

        $watchers[] = Revolt\EventLoop::unreference(Revolt\EventLoop::onReadable($client, function () use ($server, $client, $close, &$buffer, &$armed): void {
            $data = fread($client, 65536);

            if ($data === '' || $data === false) {
                $close();

                return;
            }

            $buffer .= $data;

            while (strlen($buffer) >= 4) {
                $length = unpack('V', substr($buffer, 0, 3)."\0")[1];

                if (strlen($buffer) < 4 + $length) {
                    break;
                }

                $packet = substr($buffer, 0, 4 + $length);
                $sequence = ord($packet[3]);
                $payload = substr($packet, 4);
                $buffer = substr($buffer, 4 + $length);

                if ($sequence === 0 && $payload === "\x1f") {
                    $this->resets++;

                    if ($this->mode === 'unsupported') {
                        fwrite($client, self::error(1047, '08S01', 'Unknown command'));

                        continue;
                    }

                    $armed = true;
                } elseif ($armed && $sequence === 0 && ($payload[0] ?? '') === "\x03") {
                    $armed = false;
                    $this->failedInits++;
                    fwrite($client, self::error(1146, '42S02', 'Injected session re-init failure'));

                    continue;
                }

                fwrite($server, $packet);
            }
        }));
    }

    private static function error(int $code, string $state, string $message): string
    {
        $payload = "\xff".pack('v', $code).'#'.$state.$message;

        return substr(pack('V', strlen($payload)), 0, 3)."\x01".$payload;
    }
}

/**
 * A pinning connection through a SessionResetInjectingProxy, with error_log() going to a file.
 *
 * @return array{Connection, SessionResetInjectingProxy, string}
 */
function sessionPinningProxyConnection(string $mode): array
{
    $proxy = new SessionResetInjectingProxy($mode);
    $connection = sessionPinningConnection(1, ['host' => '127.0.0.1', 'port' => $proxy->port]);
    $log = tempnam(sys_get_temp_dir(), 'fledge-pin-log');

    return [$connection, $proxy, $log];
}

it('logs once and closes dirty connections from then on when the server refuses the reset', function () {
    [$connection, $proxy, $log] = sessionPinningProxyConnection('unsupported');
    $previous = ini_set('error_log', $log);

    try {
        $dirty = fn () => inPinningFiber(function () use ($connection) {
            $connection->unprepared('SET @fledge_pin = 1');

            return $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        });

        $first = $dirty();
        $second = $dirty();
        $after = inPinningFiber(fn () => $connection->selectOne('SELECT CONNECTION_ID() AS id, @fledge_pin AS v'));
        $logged = substr_count((string) file_get_contents($log), 'does not support COM_RESET_CONNECTION');
        $connection->getPdo()->close();
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
        @unlink($log);
        $proxy->stop();
    }

    expect($logged)->toBe(1)
        ->and($proxy->resets)->toBe(1)
        ->and($proxy->accepted)->toBe(3)
        ->and(array_unique([$first, $second, $after->id]))->toHaveCount(3)
        ->and($after->v)->toBeNull();
});

it('closes the connection when the session re-init after a reset fails', function () {
    [$connection, $proxy, $log] = sessionPinningProxyConnection('fail-init');
    @unlink($log);

    try {
        $first = inPinningFiber(function () use ($connection) {
            $connection->unprepared('SET @fledge_pin = 1');

            return $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        });

        $after = inPinningFiber(fn () => $connection->selectOne('SELECT CONNECTION_ID() AS id, @fledge_pin AS v'));
        $connection->getPdo()->close();
    } finally {
        $proxy->stop();
    }

    expect($proxy->resets)->toBe(1)
        ->and($proxy->failedInits)->toBe(1)
        ->and($proxy->accepted)->toBe(2)
        ->and($after->id)->not->toBe($first)
        ->and($after->v)->toBeNull();
});
