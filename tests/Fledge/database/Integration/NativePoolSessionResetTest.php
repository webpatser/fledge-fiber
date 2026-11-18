<?php

use Fledge\Fiber\Database\Native\LeaseOwnershipException;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Session state between lessees of the native pool, against real MySQL (13306)
 * and MariaDB (13307) with pdo_mysql made fiber-aware by fiberio:
 * php -d extension=<path>/fiberio.so vendor/bin/pest <this file>
 *
 * The pool holds one physical connection, so every lessee gets the same
 * connection unless the previous lessee's was discarded. A lessee that may
 * have changed its session (user or session variables, temporary tables,
 * named locks, the current database, or raw PDO use) never hands that session
 * to the next lessee; a lessee that ran only clean statements returns its
 * connection without any extra round trip.
 */

/**
 * A stock blocking pdo_mysql connection outside the pool, for DDL and for
 * watching named locks from another session.
 */
function nativeSessionResetAdmin(string $flavor): PDO
{
    $config = $flavor === 'mysql' ? mysqlConfig() : mariadbConfig();

    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('SET SESSION lock_wait_timeout = 10, innodb_lock_wait_timeout = 10');

    return $pdo;
}

/**
 * A native connection on a pool of one physical connection; $opened->count
 * counts the physical connections the pool opened.
 */
function nativeSessionResetConnection(string $flavor, stdClass $opened): NativeMySqlConnection|NativeMariaDbConnection
{
    if (($reason = nativeDriverSkipReason($flavor)) !== null) {
        test()->markTestSkipped($reason);
    }

    $config = nativeDriverConfig($flavor, ['pool_size' => 1]);
    $connector = $flavor === 'mysql' ? new NativeMySqlConnector : new NativeMariaDbConnector;

    // Enables fiberio exactly as the driver does; the pool it returns opens nothing.
    $connector->connect($config)->close();

    $stock = (fn () => $this->stockConnector())->call($connector);

    $pool = new NativePdoPool(function () use ($stock, $config, $opened) {
        $opened->count++;

        return $stock->connect($config);
    }, 1, 60.0);

    return $flavor === 'mysql'
        ? new NativeMySqlConnection($pool, $config['database'], '', $config)
        : new NativeMariaDbConnection($pool, $config['database'], '', $config);
}

/**
 * The calling session's Questions counter (statements the server executed for it).
 */
function nativeSessionResetQuestions(string $flavor, NativeMySqlConnection|NativeMariaDbConnection $db): int
{
    $table = $flavor === 'mysql' ? 'performance_schema.session_status' : 'information_schema.SESSION_STATUS';

    return (int) $db->scalar("SELECT VARIABLE_VALUE FROM {$table} WHERE VARIABLE_NAME = 'Questions'");
}

beforeEach(function () {
    $this->resetFlavor = null;
    $this->resetConnection = null;
});

afterEach(function () {
    $this->resetConnection?->disconnect();

    if ($this->resetFlavor !== null) {
        nativeSessionResetAdmin($this->resetFlavor)->exec('DROP TABLE IF EXISTS _native_session_reset');
    }

    nativeDriverReset();
});

it('never hands user variables, session variables, temporary tables or named locks to the next lessee', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $lock = "fledge_session_reset_{$flavor}";

    $first = async(fn () => $db->transaction(function ($c) use ($lock) {
        $c->statement('SET @fledge_reset = 42');
        $c->statement('SET SESSION group_concat_max_len = 4096');
        $c->statement('CREATE TEMPORARY TABLE _fledge_reset_tmp (id INT)');

        return [
            'id' => (int) $c->scalar('SELECT CONNECTION_ID()'),
            'locked' => (int) $c->scalar("SELECT GET_LOCK('{$lock}', 0)"),
            'var' => (int) $c->scalar('SELECT @fledge_reset'),
            'session' => (int) $c->scalar('SELECT @@session.group_concat_max_len'),
        ];
    }))->await();

    // The first lessee really had all four: the test is not vacuous.
    expect($first)->toMatchArray(['locked' => 1, 'var' => 42, 'session' => 4096]);

    // Another session can take the lock as soon as the first lessee's session is gone;
    // a session kept idle in the pool would still hold it and this would time out with 0.
    $admin = nativeSessionResetAdmin($flavor);
    $adminLocked = (int) $admin->query("SELECT GET_LOCK('{$lock}', 2)")->fetchColumn();
    $admin->query("SELECT RELEASE_LOCK('{$lock}')");

    $second = async(function () use ($db) {
        try {
            $db->select('SELECT * FROM _fledge_reset_tmp');
            $tmp = 'still there';
        } catch (QueryException $e) {
            $tmp = $e->errorInfo[1] ?? null;
        }

        return [
            'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
            'var' => $db->scalar('SELECT @fledge_reset'),
            'session' => (int) $db->scalar('SELECT @@session.group_concat_max_len'),
            'global' => (int) $db->scalar('SELECT @@global.group_concat_max_len'),
            'tmp' => $tmp,
        ];
    })->await();

    expect($adminLocked)->toBe(1)
        // The reset is a fresh physical connection, never the first lessee's session.
        ->and($second['id'])->not->toBe($first['id'])
        ->and($opened->count)->toBeGreaterThan(1)
        ->and($second['var'])->toBeNull()
        ->and($second['session'])->toBe($second['global'])
        ->and($second['tmp'])->toBe(1146)
        ->and(count($db->getRawPdo()))->toBeLessThanOrEqual(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('resets a lease that changed its session outside a transaction, through a statement or the raw PDO', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $config = nativeDriverConfig($flavor);

    $db->statement('SET @fledge_plain = 1');
    $plain = $db->scalar('SELECT @fledge_plain');

    // A PDO taken outside any scope stays leased until the fiber's next outermost
    // scope ends, so the lessee still sees its own raw SET; the next one does not.
    $own = async(function () use ($db) {
        $db->getPdo()->exec('SET @fledge_raw = 7');

        return (int) $db->scalar('SELECT @fledge_raw');
    })->await();
    $raw = $db->scalar('SELECT @fledge_raw');

    // Abandoned: the fiber ends with the raw lease still held, so its scope is destroyed.
    async(function () use ($db) {
        $db->getPdo()->exec('SET @fledge_abandoned = 3');
    })->await();
    $abandoned = $db->scalar('SELECT @fledge_abandoned');

    // The configured database is selected again for the next lessee.
    $db->unprepared('USE information_schema');
    $database = $db->scalar('SELECT DATABASE()');

    expect($plain)->toBeNull()
        ->and($own)->toBe(7)
        ->and($raw)->toBeNull()
        ->and($abandoned)->toBeNull()
        ->and($database)->toBe($config['database'])
        ->and(count($db->getRawPdo()))->toBeLessThanOrEqual(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('returns a clean lease to the pool without a reset or any extra round trip', function (string $flavor) {
    $this->resetFlavor = $flavor;
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    $admin = nativeSessionResetAdmin($flavor);
    $admin->exec('DROP TABLE IF EXISTS _native_session_reset');
    $admin->exec('CREATE TABLE _native_session_reset (id INT PRIMARY KEY, label VARCHAR(20) NOT NULL) ENGINE=InnoDB');

    // What one statement through the connection costs in Questions (the counter read itself).
    $q0 = nativeSessionResetQuestions($flavor, $db);
    $q1 = nativeSessionResetQuestions($flavor, $db);
    $perStatement = $q1 - $q0;

    // Five clean lessees, each in its own fiber: plain SELECT and DML only.
    async(fn () => $db->table('_native_session_reset')->insert(['id' => 1, 'label' => 'a']))->await();
    async(fn () => $db->table('_native_session_reset')->where('id', 1)->update(['label' => 'b']))->await();
    async(fn () => $db->table('_native_session_reset')->count())->await();
    async(fn () => $db->select('SELECT label FROM _native_session_reset WHERE id = ?', [1]))->await();
    async(fn () => $db->table('_native_session_reset')->where('id', 1)->delete())->await();

    $q2 = nativeSessionResetQuestions($flavor, $db);

    // Reading CONNECTION_ID() or DATABASE() reads the session without changing it.
    $ids = [
        (int) async(fn () => $db->scalar('SELECT CONNECTION_ID()'))->await(),
        (int) async(fn () => $db->scalar('SELECT CONNECTION_ID() + 0 * LENGTH(DATABASE())'))->await(),
    ];

    expect($ids[0])->toBe($ids[1])
        ->and($perStatement)->toBeGreaterThan(0)
        // Five statements plus the counter read: not one statement more on this session.
        ->and($q2 - $q1)->toBe(6 * $perStatement)
        // One physical connection served every lessee: no reconnect either.
        ->and($opened->count)->toBe(1)
        ->and($db->getRawPdo()->getIdleCount())->toBe(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('keeps a lease clean when a query listener only quotes through the PDO', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    // What Telescope's QueryWatcher and Debugbar do for string bindings.
    $quoted = [];
    $db->setEventDispatcher(new Dispatcher);
    $db->listen(function () use ($db, &$quoted) {
        $quoted[] = $db->getPdo()->quote('x');
    });

    $ids = [
        (int) async(fn () => $db->scalar('SELECT CONNECTION_ID() + 0 * LENGTH(?)', ['a']))->await(),
        (int) async(fn () => $db->scalar('SELECT CONNECTION_ID() + 0 * LENGTH(?)', ['b']))->await(),
        (int) async(fn () => $db->scalar('SELECT CONNECTION_ID()'))->await(),
    ];

    expect($quoted)->toBe(["'x'", "'x'", "'x'"])
        ->and($ids[0])->toBe($ids[1])
        ->and($ids[1])->toBe($ids[2])
        ->and($opened->count)->toBe(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('judges raw PDO use by what it runs: exec of a SET is dirty, query of a SELECT stays clean', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    $first = async(function () use ($db) {
        $selected = (int) $db->getPdo()->query('select 1')->fetchColumn();

        return [$selected, (int) $db->scalar('SELECT CONNECTION_ID()')];
    })->await();
    $afterQuery = (int) async(fn () => $db->scalar('SELECT CONNECTION_ID()'))->await();

    // A clean raw query: the same physical connection serves the next lessee.
    expect($first[0])->toBe(1)
        ->and($afterQuery)->toBe($first[1])
        ->and($opened->count)->toBe(1);

    $own = async(function () use ($db) {
        $db->getPdo()->exec('SET @x = 1');

        // One statement: the raw lease is given back when this scope ends.
        $row = $db->selectOne('SELECT CONNECTION_ID() AS id, @x AS x');

        return [(int) $row->id, (int) $row->x];
    })->await();
    $next = async(fn () => [(int) $db->scalar('SELECT CONNECTION_ID()'), $db->scalar('SELECT @x')])->await();

    expect($own)->toBe([$afterQuery, 1])
        ->and($next[0])->not->toBe($own[0])
        ->and($next[1])->toBeNull()
        ->and($opened->count)->toBeGreaterThan(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('keeps a lease clean across a transaction with DML, a nested savepoint and a savepoint rollback', function (string $flavor) {
    $this->resetFlavor = $flavor;
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    $admin = nativeSessionResetAdmin($flavor);
    $admin->exec('DROP TABLE IF EXISTS _native_session_reset');
    $admin->exec('CREATE TABLE _native_session_reset (id INT PRIMARY KEY, label VARCHAR(20) NOT NULL) ENGINE=InnoDB');

    $inside = (int) async(fn () => $db->transaction(function ($c) {
        $c->table('_native_session_reset')->insert(['id' => 1, 'label' => 'a']);

        // SAVEPOINT trans2, kept.
        $c->transaction(fn ($c) => $c->table('_native_session_reset')->where('id', 1)->update(['label' => 'b']));

        // SAVEPOINT trans2 again, then ROLLBACK TO SAVEPOINT trans2.
        try {
            $c->transaction(function ($c) {
                $c->table('_native_session_reset')->where('id', 1)->update(['label' => 'c']);

                throw new RuntimeException('roll back to the savepoint');
            });
        } catch (RuntimeException) {
        }

        return $c->scalar('SELECT CONNECTION_ID()');
    }))->await();

    $after = (int) async(fn () => $db->scalar('SELECT CONNECTION_ID()'))->await();
    $label = async(fn () => $db->table('_native_session_reset')->where('id', 1)->value('label'))->await();

    expect($label)->toBe('b')
        ->and($after)->toBe($inside)
        ->and($opened->count)->toBe(1)
        ->and($db->getRawPdo()->getIdleCount())->toBe(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('rejects setAttribute() and server round trips through a PDO the caller does not lease, so the next lessee keeps the defaults', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    // A reference kept past the fiber that leased it: the connection now sits idle in the pool.
    $stale = async(fn () => $db->getPdo())->await();
    $idBefore = (int) async(fn () => $db->scalar('SELECT CONNECTION_ID()'))->await();

    $errors = [];

    foreach ([
        fn () => $stale->setAttribute(PDO::ATTR_AUTOCOMMIT, false),
        fn () => $stale->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT),
        fn () => $stale->getAttribute(PDO::ATTR_SERVER_INFO),
    ] as $call) {
        try {
            $call();
            $errors[] = null;
        } catch (LeaseOwnershipException $e) {
            $errors[] = $e->getMessage();
        }
    }

    // Purely local attributes stay readable without a lease.
    $version = $stale->getAttribute(PDO::ATTR_SERVER_VERSION);

    // Reading @@session.autocommit counts as dirty, so it comes last: nothing reopens before it.
    $next = async(fn () => [
        'errmode' => $db->getPdo()->getAttribute(PDO::ATTR_ERRMODE),
        'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
        'autocommit' => (int) $db->scalar('SELECT @@session.autocommit'),
    ])->await();

    expect($errors)->toHaveCount(3)->each->toBeString()->toContain('released back');

    expect($version)->toBeString()->not->toBeEmpty()
        // The rejected calls changed nothing: the same clean session serves the next lessee.
        ->and($next)->toBe(['errmode' => PDO::ERRMODE_EXCEPTION, 'id' => $idBefore, 'autocommit' => 1])
        ->and($opened->count)->toBe(1);

    // A reference to another fiber's live lease is rejected the same way.
    $box = [];

    $holder = async(function () use ($db, &$box) {
        $box['pdo'] = $db->getPdo();
        delay(0.05);

        return [(int) $db->scalar('SELECT @@session.autocommit'), $box['pdo']->getAttribute(PDO::ATTR_ERRMODE)];
    });

    delay(0.01);

    expect(fn () => $box['pdo']->setAttribute(PDO::ATTR_AUTOCOMMIT, false))
        ->toThrow(LeaseOwnershipException::class, 'leased to another fiber')
        ->and(fn () => $box['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT))
        ->toThrow(LeaseOwnershipException::class, 'leased to another fiber')
        ->and(fn () => $box['pdo']->getAttribute(PDO::ATTR_SERVER_INFO))
        ->toThrow(LeaseOwnershipException::class, 'leased to another fiber')
        ->and($holder->await())->toBe([1, PDO::ERRMODE_EXCEPTION])
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('discards the lease of an owner that changed an attribute', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    $first = async(function () use ($db) {
        $id = (int) $db->scalar('SELECT CONNECTION_ID()');

        // A purely local attribute, so only the lease's dirty flag can drop the connection.
        $pdo = $db->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        return [$id, $pdo->leaseGuard()->sessionChanged(), $pdo->getAttribute(PDO::ATTR_ERRMODE)];
    })->await();

    $next = async(fn () => [
        (int) $db->scalar('SELECT CONNECTION_ID()'),
        $db->getPdo()->getAttribute(PDO::ATTR_ERRMODE),
    ])->await();

    expect($first[1])->toBeTrue()
        ->and($first[2])->toBe(PDO::ERRMODE_SILENT)
        ->and($next[0])->not->toBe($first[0])
        ->and($next[1])->toBe(PDO::ERRMODE_EXCEPTION)
        ->and($opened->count)->toBe(2)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('hands a fiber waiting on a pool of one a clean session when the dirty lessee lets go', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $lock = "fledge_session_waiter_{$flavor}";
    $waiting = null;

    $a = async(function () use ($db, $lock, &$waiting) {
        return $db->transaction(function ($c) use ($db, $lock, &$waiting) {
            $c->statement('SET @x = 42');

            $held = [
                'id' => (int) $c->scalar('SELECT CONNECTION_ID()'),
                'locked' => (int) $c->scalar("SELECT GET_LOCK('{$lock}', 0)"),
                'x' => (int) $c->scalar('SELECT @x'),
            ];

            // B queues for the only connection meanwhile.
            delay(0.05);
            $waiting = $db->getRawPdo()->getWaitingCount();

            return $held;
        });
    });

    $b = async(function () use ($db, $lock) {
        delay(0.01);

        return $db->transaction(fn ($c) => [
            'id' => (int) $c->scalar('SELECT CONNECTION_ID()'),
            'x' => $c->scalar('SELECT @x'),
            // Waits up to 2 s for the server to drop A's closed session and its lock.
            'locked' => (int) $c->scalar("SELECT GET_LOCK('{$lock}', 2)"),
            'released' => (int) $c->scalar("SELECT RELEASE_LOCK('{$lock}')"),
        ]);
    });

    [$first, $second] = await([$a, $b]);

    expect($first)->toMatchArray(['locked' => 1, 'x' => 42])
        ->and($waiting)->toBe(1)
        ->and($second['id'])->not->toBe($first['id'])
        ->and($second['x'])->toBeNull()
        ->and($second['locked'])->toBe(1)
        ->and($second['released'])->toBe(1)
        ->and($opened->count)->toBe(2)
        ->and(count($db->getRawPdo()))->toBeLessThanOrEqual(1)
        ->and($db->getRawPdo()->getWaitingCount())->toBe(0)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);
