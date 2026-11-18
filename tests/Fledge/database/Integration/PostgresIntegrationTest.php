<?php

use Fledge\Async\Database\Postgres\PgSqlConnection;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresConnectionPool;
use Fledge\Async\Database\Postgres\PostgresQueryError;
use Fledge\Async\Database\Postgres\PqConnection;
use Fledge\Async\Database\SqlException;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;

uses()->beforeEach(function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('PostgreSQL not available on port '.test_env('FLEDGE_TEST_POSTGRES_PORT', 15432));
    }
});

it('connects and runs SELECT 1', function () {
    $connector = new FledgePostgresConnector;
    $pdo = $connector->connect(postgresConfig());

    expect($pdo)->toBeInstanceOf(FledgePostgresPdo::class);

    $result = $pdo->exec('SELECT 1');
    expect($result)->toBeInt();

    $pdo->close();
});

it('creates a table, inserts, and selects', function () {
    $connector = new FledgePostgresConnector;
    $pdo = $connector->connect(postgresConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_pg_test');
    $pdo->exec('CREATE TABLE _fledge_pg_test (id SERIAL PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO _fledge_pg_test (name) VALUES ('hello')");

    $stmt = $pdo->prepare('SELECT name FROM _fledge_pg_test');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('hello');

    $pdo->exec('DROP TABLE IF EXISTS _fledge_pg_test');
    $pdo->close();
});

it('keeps session settings across pool checkouts despite DISCARD ALL', function () {
    $connector = new FledgePostgresConnector;
    $pdo = $connector->connect([
        'pool_size' => 1,
        'isolation_level' => 'repeatable read',
        'search_path' => 'public,pg_catalog',
        'synchronous_commit' => 'off',
    ] + postgresConfig());

    $property = new ReflectionProperty($pdo, 'pool');
    $pool = $property->getValue($pdo);

    $show = function (string $sql, string $column) use ($pool): string {
        foreach ($pool->query($sql) as $row) {
            return $row[$column];
        }

        throw new RuntimeException("No row returned for {$sql}");
    };

    // Every query checks a connection out of the pool, which runs DISCARD ALL
    // first. RESET ALL (part of DISCARD ALL) restores startup-packet session
    // defaults, so settings passed via options survive; plain SETs would not.
    foreach (range(1, 3) as $checkout) {
        expect($show('SHOW search_path', 'search_path'))->toBe('"public","pg_catalog"')
            ->and($show('SHOW default_transaction_isolation', 'default_transaction_isolation'))->toBe('repeatable read')
            ->and($show('SHOW synchronous_commit', 'synchronous_commit'))->toBe('off');
    }

    $pdo->close();
});

function asyncPostgresConfig(?string $database = null): PostgresConfig
{
    $c = postgresConfig();

    return new PostgresConfig($c['host'], $c['port'], $c['username'], $c['password'], $database ?? $c['database']);
}

function adminPostgresPdo(?string $database = null): PDO
{
    $c = postgresConfig();
    $database ??= $c['database'];

    return new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$database}", $c['username'], $c['password']);
}

/** Backend pid of the session whose last statement was LISTEN on the channel. */
function listenerBackendPid(string $channel): int
{
    $pid = adminPostgresPdo()
        ->query("SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND query = 'LISTEN \"{$channel}\"'")
        ->fetchColumn();

    expect($pid)->not->toBeFalse();

    return (int) $pid;
}

/** The payload of the next notification, failing the test instead of waiting for ever. */
function firstPayload(Traversable $listener): string
{
    return async(function () use ($listener): string {
        foreach ($listener as $notification) {
            return $notification->payload;
        }

        return '';
    })->await(new TimeoutCancellation(3));
}

it('releases the pool slot when DISCARD ALL fails on checkout', function () {
    $pool = new PostgresConnectionPool(asyncPostgresConfig(), maxConnections: 1);

    $pid = null;
    foreach ($pool->query('SELECT pg_backend_pid() AS pid') as $row) {
        $pid = (int) $row['pid'];
    }

    // The server ends the idle pooled session, so the DISCARD ALL of the next checkout fails.
    adminPostgresPdo()->query("SELECT pg_terminate_backend({$pid})");
    usleep(200_000);

    try {
        $pool->query('SELECT 1');
        $failed = false;
    } catch (Throwable) {
        $failed = true;
    }
    expect($failed)->toBeTrue();

    // With the slot leaked the only connection never comes back and this waits forever.
    $next = async(function () use ($pool): int {
        foreach ($pool->query('SELECT pg_backend_pid() AS pid') as $row) {
            return (int) $row['pid'];
        }

        return 0;
    })->await(new TimeoutCancellation(5));

    expect($next)->toBeGreaterThan(0)->not->toBe($pid);

    $pool->close();
});

it('checks the listening connection out again after the last listener left', function () {
    $pool = new PostgresConnectionPool(asyncPostgresConfig(), maxConnections: 2);

    $first = $pool->listen('_fledge_ch_one');
    $first->unlisten();
    delay(0); // The pooled listener queues its release on the loop.

    // The listening connection went back to the pool. A new listener must check a
    // connection out again, not reuse the one that is idle in the pool, or the next
    // checkout runs DISCARD ALL (which includes UNLISTEN *) on the listener's session.
    $second = $pool->listen('_fledge_ch_two');
    $listenerPid = listenerBackendPid('_fledge_ch_two');

    // The listener holds its connection: a query must get another session.
    $queryPid = 0;
    foreach ($pool->query('SELECT pg_backend_pid() AS pid') as $row) {
        $queryPid = (int) $row['pid'];
    }
    expect($queryPid)->toBeGreaterThan(0)->not->toBe($listenerPid);

    $pool->notify('_fledge_ch_two', 'hello');

    expect(firstPayload($second))->toBe('hello');

    $second->unlisten();
    delay(0);

    $pool->close();
});

it('opens a fresh listening connection after the listening session died and its listener was dropped', function () {
    $pool = new PostgresConnectionPool(asyncPostgresConfig(), maxConnections: 2);

    $listener = $pool->listen('_fledge_dead_a');
    adminPostgresPdo()->query('SELECT pg_terminate_backend('.listenerBackendPid('_fledge_dead_a').')');
    usleep(200_000);

    unset($listener);
    delay(0.1);

    $next = $pool->listen('_fledge_dead_b');
    $pool->notify('_fledge_dead_b', 'alive');

    expect(firstPayload($next))->toBe('alive');

    $next->unlisten();
    delay(0);
    $pool->close();
});

it('fails listeners of a dead listening session and listens anew while they are still held', function () {
    $pool = new PostgresConnectionPool(asyncPostgresConfig(), maxConnections: 2);

    $held = $pool->listen('_fledge_dead_c');
    $alive = $pool->listen('_fledge_dead_d');
    adminPostgresPdo()->query('SELECT pg_terminate_backend('.listenerBackendPid('_fledge_dead_d').')');

    // The listener on the dead session errors instead of waiting for ever.
    $error = null;
    try {
        firstPayload($alive);
    } catch (Throwable $exception) {
        $error = $exception;
    }
    expect($error)->toBeInstanceOf(SqlException::class);

    // The dead listeners are still held: a new listener must not reuse their session.
    $next = $pool->listen('_fledge_dead_e');
    $pool->notify('_fledge_dead_e', 'first');
    expect(firstPayload($next))->toBe('first');

    // Releasing the old listeners must not release the new listening connection.
    unset($held, $alive);
    delay(0.1);

    $pool->notify('_fledge_dead_e', 'second');
    expect(firstPayload($next))->toBe('second');

    $next->unlisten();
    delay(0);
    $pool->close();
});

it('keeps the type table of each database apart', function () {
    $database = 'fledge_test_types';
    $admin = adminPostgresPdo();
    $admin->exec("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    $admin->exec("CREATE DATABASE {$database}");

    try {
        adminPostgresPdo($database)->exec("CREATE TYPE _fledge_mood AS ENUM ('happy', 'sad')");

        // Fill the type table of the default database first: same host, port and user.
        $default = PgSqlConnection::connect(asyncPostgresConfig());
        $default->query('SELECT 1');

        $other = PgSqlConnection::connect(asyncPostgresConfig($database));
        $row = $other->query("SELECT ARRAY['happy', 'sad']::_fledge_mood[] AS m")->fetchRow();

        // The enum array type only exists in the other database: with the type table of
        // the default database it is unknown and comes back unparsed.
        expect($row['m'])->toBe(['happy', 'sad']);

        $default->close();
        $other->close();
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    }
});

it('returns every result of a multi-statement query whose later statements are still running', function (string $class, string $extension) {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("The {$extension} extension is not loaded");
    }

    $connection = $class::connect(asyncPostgresConfig());

    // The first result arrives while the server still sleeps in the second statement, so
    // libpq reports busy when the handle looks for the next result.
    $first = $connection->query('SELECT 1 AS a; SELECT pg_sleep(0.3)::text AS s; SELECT 3 AS c');
    expect($first->fetchRow())->toBe(['a' => 1]);

    $second = $first->getNextResult();
    expect($second)->not->toBeNull()
        ->and($second->fetchRow())->toBe(['s' => '']);

    $third = $second->getNextResult();
    expect($third)->not->toBeNull()
        ->and($third->fetchRow())->toBe(['c' => 3])
        ->and($third->getNextResult())->toBeNull();

    // All results were consumed, so the connection is idle and takes the next query.
    expect($connection->query('SELECT 4 AS d')->fetchRow())->toBe(['d' => 4]);

    $connection->close();
})->with([
    'pgsql' => [PgSqlConnection::class, 'pgsql'],
    'pq' => [PqConnection::class, 'pq'],
]);

it('keeps the connection usable after a later statement of a multi-statement query fails', function (string $class, string $extension, string $sql) {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("The {$extension} extension is not loaded");
    }

    $connection = $class::connect(asyncPostgresConfig());

    expect(fn () => $connection->query($sql))
        ->toThrow(PostgresQueryError::class, 'division by zero');

    // Every outstanding result was drained, so the connection takes the next query.
    expect($connection->query('SELECT 4 AS d')->fetchRow())->toBe(['d' => 4]);

    $connection->close();
})->with([
    'pgsql' => [PgSqlConnection::class, 'pgsql'],
    'pq' => [PqConnection::class, 'pq'],
])->with([
    'second statement fails' => ['SELECT 1 AS a; SELECT 1/0; SELECT 3 AS c'],
    'fails after a running statement' => ['SELECT 1 AS a; SELECT pg_sleep(0.3)::text AS s; SELECT 1/0'],
]);

it('handles prepared statements with placeholder conversion', function () {
    $connector = new FledgePostgresConnector;
    $pdo = $connector->connect(postgresConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_pg_params');
    $pdo->exec('CREATE TABLE _fledge_pg_params (id SERIAL PRIMARY KEY, a TEXT, b INT)');
    $pdo->exec("INSERT INTO _fledge_pg_params (a, b) VALUES ('x', 1), ('y', 2), ('z', 3)");

    $stmt = $pdo->prepare('SELECT a, b FROM _fledge_pg_params WHERE a = ? AND b = ?');
    $stmt->bindValue(1, 'y');
    $stmt->bindValue(2, 2, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['a'])->toBe('y');

    $pdo->exec('DROP TABLE IF EXISTS _fledge_pg_params');
    $pdo->close();
});
