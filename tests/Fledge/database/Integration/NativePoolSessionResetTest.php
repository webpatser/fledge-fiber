<?php

use Fledge\Async\DeferredFuture;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\Native\LeaseOwnershipException;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Illuminate\Database\LostConnectionException;
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
 * The pool holds one physical connection (unless a test asks for more), so
 * every lessee gets the same connection unless the previous lessee's was
 * discarded. A lessee that may have changed its session (user or session
 * variables, temporary tables, named locks, the current database, or raw PDO
 * use) keeps that connection pinned for its own following statements and
 * never hands that session to the next lessee; a lessee that ran only clean
 * statements returns its connection without any extra round trip.
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
function nativeSessionResetConnection(string $flavor, stdClass $opened, int $size = 1): NativeMySqlConnection|NativeMariaDbConnection
{
    if (($reason = nativeDriverSkipReason($flavor)) !== null) {
        test()->markTestSkipped($reason);
    }

    $config = nativeDriverConfig($flavor, ['pool_size' => $size]);
    $connector = $flavor === 'mysql' ? new NativeMySqlConnector : new NativeMariaDbConnector;

    // Enables fiberio exactly as the driver does; the pool it returns opens nothing.
    $connector->connect($config)->close();

    $stock = (fn () => $this->stockConnector())->call($connector);

    $makePool = fn () => new NativePdoPool(function () use ($stock, $config, $opened) {
        $opened->count++;

        return $stock->connect($config);
    }, $size, 60.0);

    $connection = $flavor === 'mysql'
        ? new NativeMySqlConnection($makePool(), $config['database'], '', $config)
        : new NativeMariaDbConnection($makePool(), $config['database'], '', $config);

    // What DatabaseManager::reconnect() installs: a fresh pool after a disconnect().
    return $connection->setReconnector(fn ($c) => $c->setPdo($makePool()));
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

    // The lessee keeps its own session for its next statements; the next lessee does not get it.
    $ownPlain = (int) async(function () use ($db) {
        $db->statement('SET @fledge_plain = 1');

        return $db->scalar('SELECT @fledge_plain');
    })->await();
    $plain = async(fn () => $db->scalar('SELECT @fledge_plain'))->await();

    $own = async(function () use ($db) {
        $db->getPdo()->exec('SET @fledge_raw = 7');

        return (int) $db->scalar('SELECT @fledge_raw');
    })->await();
    $raw = async(fn () => $db->scalar('SELECT @fledge_raw'))->await();

    // Abandoned: the fiber ends with the raw lease still held, so its scope is destroyed.
    async(function () use ($db) {
        $db->getPdo()->exec('SET @fledge_abandoned = 3');
    })->await();
    $abandoned = async(fn () => $db->scalar('SELECT @fledge_abandoned'))->await();

    // The lessee stays in the database it chose; the next one gets the configured database again.
    $ownDatabase = async(function () use ($db) {
        $db->unprepared('USE information_schema');

        return $db->scalar('SELECT DATABASE()');
    })->await();
    $database = async(fn () => $db->scalar('SELECT DATABASE()'))->await();

    expect($ownPlain)->toBe(1)
        ->and($plain)->toBeNull()
        ->and($own)->toBe(7)
        ->and($raw)->toBeNull()
        ->and($abandoned)->toBeNull()
        ->and(strtolower($ownDatabase))->toBe('information_schema')
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

        // A separate statement: the dirty raw lease stays pinned to this fiber.
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

it('keeps foreign key checks off for the caller\'s following statements, so tables drop in any order', function (string $flavor, bool $inFiber) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $tables = ['_native_session_fk_child', '_native_session_fk_parent'];

    $admin = nativeSessionResetAdmin($flavor);

    foreach ($tables as $table) {
        $admin->exec("DROP TABLE IF EXISTS {$table}");
    }

    $admin->exec('CREATE TABLE _native_session_fk_parent (id INT PRIMARY KEY) ENGINE=InnoDB');
    $admin->exec('CREATE TABLE _native_session_fk_child (id INT PRIMARY KEY, parent_id INT NOT NULL, '
        .'FOREIGN KEY (parent_id) REFERENCES _native_session_fk_parent (id)) ENGINE=InnoDB');

    // What dropAllTables() (migrate:fresh, db:wipe, RefreshDatabase) does, as separate statements.
    $run = function () use ($db) {
        $schema = $db->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        $off = (int) $db->scalar('SELECT @@foreign_key_checks');

        // The wrong order: the parent first, while the child still references it.
        $db->statement('DROP TABLE _native_session_fk_parent');
        $db->statement('DROP TABLE _native_session_fk_child');

        $schema->enableForeignKeyConstraints();

        return [$off, (int) $db->scalar('SELECT @@foreign_key_checks')];
    };

    try {
        $result = $inFiber ? async($run)->await() : $run();
    } finally {
        $admin->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $admin->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    expect($result)->toBe([0, 1]);
})->with(['mariadb', 'mysql'])->with(['in a fiber' => true, 'in the main context' => false]);

it('keeps a user variable for the fiber that set it, never for another fiber, and gives the next lessee a fresh session', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened, 2);
    $lock = "fledge_session_pin_{$flavor}";

    $a = async(function () use ($db, $lock) {
        $db->statement('SET @x = 1');
        $locked = (int) $db->scalar("SELECT GET_LOCK('{$lock}', 0)");

        // B runs its statements meanwhile.
        delay(0.05);

        return [
            'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
            'x' => (int) $db->scalar('SELECT @x'),
            'locked' => $locked,
        ];
    });

    $b = async(function () use ($db) {
        delay(0.01);

        return ['id' => (int) $db->scalar('SELECT CONNECTION_ID()'), 'x' => $db->scalar('SELECT @x')];
    });

    [$first, $other] = await([$a, $b]);

    $next = async(fn () => [
        'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
        'x' => $db->scalar('SELECT @x'),
        // Waits up to 2 s for the server to drop A's closed session and its lock.
        'locked' => (int) $db->scalar("SELECT GET_LOCK('{$lock}', 2)"),
        'released' => (int) $db->scalar("SELECT RELEASE_LOCK('{$lock}')"),
    ])->await();

    expect($first)->toMatchArray(['x' => 1, 'locked' => 1])
        ->and($other['id'])->not->toBe($first['id'])
        ->and($other['x'])->toBeNull()
        ->and($next['id'])->not->toBe($first['id'])
        ->and($next['x'])->toBeNull()
        ->and($next['locked'])->toBe(1)
        ->and($next['released'])->toBe(1)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('runs many dirty statements of one fiber on one connection instead of reconnecting for each', function (string $flavor) {
    $this->resetFlavor = $flavor;
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    $admin = nativeSessionResetAdmin($flavor);
    $admin->exec('DROP TABLE IF EXISTS _native_session_reset');
    $admin->exec('CREATE TABLE _native_session_reset (id INT PRIMARY KEY, label VARCHAR(20) NOT NULL, FULLTEXT (label)) ENGINE=InnoDB');
    $admin->exec("INSERT INTO _native_session_reset VALUES (1, 'stoked'), (2, 'surf')");

    [$ids, $hits, $dirty] = async(function () use ($db) {
        $ids = [];
        $hits = 0;

        for ($i = 0; $i < 20; $i++) {
            $hits += $db->table('_native_session_reset')->whereFullText('label', 'surf')->count();
            // Reading a user variable counts as dirty (full-text search alone is clean).
            $ids[] = (int) $db->scalar('SELECT CONNECTION_ID() + 0 * COALESCE(@fledge_many, 0)');
        }

        return [array_values(array_unique($ids)), $hits, $db->getPdo()->leaseGuard()->sessionChanged()];
    })->await();

    $openedByFiber = $opened->count;

    async(fn () => $db->scalar('SELECT 1'))->await();

    // The user variable reads count as dirty, so the test is not vacuous.
    expect($dirty)->toBeTrue()
        ->and($hits)->toBe(20)
        ->and($ids)->toHaveCount(1)
        ->and($openedByFiber)->toBe(1)
        // One more for the next lessee, since the pinned connection was discarded.
        ->and($opened->count)->toBe(2)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('lets a fiber that pinned the only connection of a pool of one keep querying, and hands a waiter a fresh one when it ends', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $waiting = null;

    $a = async(function () use ($db, &$waiting) {
        $db->statement('SET @x = 5');
        $id = (int) $db->scalar('SELECT CONNECTION_ID()');

        // B queues for the only connection meanwhile.
        delay(0.05);
        $waiting = $db->getRawPdo()->getWaitingCount();

        $seen = [];

        for ($i = 0; $i < 3; $i++) {
            $seen[] = (int) $db->scalar('SELECT @x');
        }

        return ['id' => $id, 'x' => $seen];
    });

    $b = async(function () use ($db) {
        delay(0.01);

        return ['id' => (int) $db->scalar('SELECT CONNECTION_ID()'), 'x' => $db->scalar('SELECT @x')];
    });

    [$first, $second] = await([$a, $b]);

    expect($waiting)->toBe(1)
        ->and($first['x'])->toBe([5, 5, 5])
        ->and($second['id'])->not->toBe($first['id'])
        ->and($second['x'])->toBeNull()
        ->and($opened->count)->toBe(2)
        ->and($db->getRawPdo()->getWaitingCount())->toBe(0)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('keeps the main context session across statements until disconnect() releases it', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $pool = $db->getRawPdo();

    $db->statement('SET @x = 1');
    $x = (int) $db->scalar('SELECT @x');
    $pinned = [$pool->getLeasedCount(), count($pool)];

    $db->disconnect();

    expect($x)->toBe(1)
        ->and($pinned)->toBe([1, 1])
        ->and($opened->count)->toBe(1)
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool)->toHaveCount(0);
})->with(['mariadb', 'mysql']);

it('carries the main context session from job to job, and DB::disconnect() gives the next job a fresh one', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);

    // A queue worker's loop in the main context.
    $jobs = [
        fn () => $db->statement('SET @fledge_job = 1'),
        fn () => $db->scalar('SELECT @fledge_job'),
        fn () => $db->scalar('SELECT @fledge_job'),
    ];

    $seen = array_map(fn ($job) => (int) $job(), $jobs);
    $idBefore = (int) $db->scalar('SELECT CONNECTION_ID()');
    $pinned = $db->getRawPdo()->getLeasedCount();

    $db->disconnect();

    $after = [
        'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
        'x' => $db->scalar('SELECT @fledge_job'),
    ];

    expect($seen)->toBe([1, 1, 1])
        ->and($pinned)->toBe(1)
        ->and($after['x'])->toBeNull()
        ->and($after['id'])->not->toBe($idBefore)
        ->and($opened->count)->toBe(2);
})->with(['mariadb', 'mysql']);

it('gives the next lessee a fresh session when a pinned fiber ends by an exception', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $lock = "fledge_session_throw_{$flavor}";
    $first = [];

    $a = async(function () use ($db, $lock, &$first) {
        $db->statement('SET @fledge_throw = 9');
        $first = [
            'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
            'locked' => (int) $db->scalar("SELECT GET_LOCK('{$lock}', 0)"),
        ];

        throw new RuntimeException('job failed');
    });

    expect(fn () => $a->await(new TimeoutCancellation(10)))->toThrow(RuntimeException::class, 'job failed');

    $next = async(fn () => [
        'id' => (int) $db->scalar('SELECT CONNECTION_ID()'),
        'x' => $db->scalar('SELECT @fledge_throw'),
        // Waits up to 2 s for the server to drop the failed fiber's closed session and its lock.
        'locked' => (int) $db->scalar("SELECT GET_LOCK('{$lock}', 2)"),
        'released' => (int) $db->scalar("SELECT RELEASE_LOCK('{$lock}')"),
    ])->await(new TimeoutCancellation(10));

    expect($first['locked'])->toBe(1)
        ->and($next['id'])->not->toBe($first['id'])
        ->and($next['x'])->toBeNull()
        ->and($next['locked'])->toBe(1)
        ->and($next['released'])->toBe(1)
        ->and($opened->count)->toBe(2)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('fails a pinned fiber once with LostConnectionException when another context disconnects, never continuing on a fresh session silently', function (string $flavor) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $pinned = new DeferredFuture;
    $disconnected = new DeferredFuture;

    $a = async(function () use ($db, $pinned, $disconnected) {
        $db->statement('SET @fledge_replaced = 3');
        $id = (int) $db->scalar('SELECT CONNECTION_ID()');

        // The main context disconnects meanwhile.
        $pinned->complete();
        $disconnected->getFuture()->await(new TimeoutCancellation(5));

        try {
            $db->scalar('SELECT @fledge_replaced');
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        return [
            'id' => $id,
            'error' => $error,
            'next' => (int) $db->scalar('SELECT CONNECTION_ID()'),
            'x' => $db->scalar('SELECT @fledge_replaced'),
        ];
    });

    $pinned->getFuture()->await(new TimeoutCancellation(5));
    $db->disconnect();
    $disconnected->complete();

    $result = $a->await(new TimeoutCancellation(10));

    expect($result['error'])->toBeInstanceOf(LostConnectionException::class)
        ->and($result['next'])->not->toBe($result['id'])
        ->and($result['x'])->toBeNull()
        ->and($opened->count)->toBe(2)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql']);

it('fails a pinned fiber once when another context disconnects while its statement is in flight', function (string $flavor, bool $lockInFlight) {
    $opened = (object) ['count' => 0];
    $this->resetConnection = $db = nativeSessionResetConnection($flavor, $opened);
    $lock = "fledge_session_inflight_{$flavor}";
    $sending = new DeferredFuture;
    $events = [];

    $a = async(function () use ($db, $lock, $lockInFlight, $sending, &$events) {
        $id = (int) $db->scalar('SELECT CONNECTION_ID()');

        if (! $lockInFlight) {
            $db->statement('SET @fledge_inflight = 5');
        }

        // Nothing suspends this fiber before the statement below waits on the
        // server (its lease is held, no pool wait), so the main context's
        // disconnect runs while that statement is in flight.
        $sending->complete();
        $inFlight = $lockInFlight
            ? (int) $db->scalar("SELECT GET_LOCK('{$lock}', 0) + SLEEP(0.3)")
            : (int) $db->scalar('SELECT SLEEP(0.3)');
        $events[] = 'in-flight statement returned';

        try {
            $db->scalar('SELECT @fledge_inflight');
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        return [
            'id' => $id,
            'inFlight' => $inFlight,
            'error' => $error,
            'next' => (int) $db->scalar('SELECT CONNECTION_ID()'),
            'x' => $db->scalar('SELECT @fledge_inflight'),
            // Waits up to 2 s for the server to drop the closed session and its lock.
            'locked' => (int) $db->scalar("SELECT GET_LOCK('{$lock}', 2)"),
            'released' => (int) $db->scalar("SELECT RELEASE_LOCK('{$lock}')"),
        ];
    });

    $sending->getFuture()->await(new TimeoutCancellation(5));
    $db->disconnect();
    $events[] = 'disconnected';

    $result = $a->await(new TimeoutCancellation(10));

    expect($events)->toBe(['disconnected', 'in-flight statement returned'])
        ->and($result['inFlight'])->toBe($lockInFlight ? 1 : 0)
        ->and($result['error'])->toBeInstanceOf(LostConnectionException::class)
        ->and($result['next'])->not->toBe($result['id'])
        ->and($result['x'])->toBeNull()
        ->and($result['locked'])->toBe(1)
        ->and($result['released'])->toBe(1)
        ->and($opened->count)->toBe(2)
        ->and($db->getRawPdo()->getLeasedCount())->toBe(0);
})->with(['mariadb', 'mysql'])->with([
    'session variable set before' => false,
    'named lock taken in flight' => true,
]);
