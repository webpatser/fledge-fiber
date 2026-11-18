<?php

use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresConnectionPool;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\DeferredFuture;
use Fledge\Async\TimeoutCancellation;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;
use Illuminate\Database\LostConnectionDetector;
use Tests\Fledge\database\Stubs\RecordingPostgresConnector;

use function Fledge\Async\async;

/**
 * The pool resets (DISCARD ALL) only a connection whose session may have changed: a clean
 * SELECT costs no extra round trip, and nothing one fiber set is visible to the next fiber
 * that gets the connection. Pool size 1 forces every checkout onto the same session.
 */
function resetTestConfig(): PostgresConfig
{
    $c = postgresConfig();

    return new PostgresConfig($c['host'], $c['port'], $c['username'], $c['password'], $c['database']);
}

function resetTestAdmin(): PDO
{
    $c = postgresConfig();

    return new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
}

/**
 * Run $work in its own fiber (a new "request" on the pool) and wait for it.
 */
function inResetFiber(Closure $work): mixed
{
    return async($work)->await(new TimeoutCancellation(10, 'Fiber did not finish: deadlock?'));
}

/**
 * Terminate a backend from a second connection and wait (bounded) until the server shows it gone.
 */
function resetTestTerminate(int $pid): void
{
    $admin = resetTestAdmin();
    $admin->query("SELECT pg_terminate_backend({$pid})")->fetchAll();

    for ($i = 0; $i < 500; $i++) {
        if ((int) $admin->query("SELECT count(*) FROM pg_stat_activity WHERE pid = {$pid}")->fetchColumn() === 0) {
            return;
        }

        usleep(10_000);
    }

    throw new RuntimeException("Backend {$pid} did not exit");
}

/**
 * Run $work and return [its result, every PHP notice or warning raised meanwhile].
 */
function resetRecordingErrors(Closure $work): array
{
    $errors = [];
    set_error_handler(function (int $code, string $message) use (&$errors): bool {
        $errors[] = $message;

        return true;
    });

    try {
        return [$work(), $errors];
    } finally {
        restore_error_handler();
    }
}

/**
 * A PDO shim on a pool of size 1 that records the SQL it sends.
 */
function resetTestPdo(RecordingPostgresConnector $recorder): FledgePostgresPdo
{
    $connector = new class($recorder) extends FledgePostgresConnector
    {
        public function __construct(private RecordingPostgresConnector $recorder) {}

        protected function createPool(PostgresConfig $config, array $appConfig): PostgresConnectionPool
        {
            return new PostgresConnectionPool($config, 1, connector: $this->recorder);
        }
    };

    return $connector->connect(postgresConfig());
}

/**
 * First column of the first row, or null.
 */
function resetScalar(PostgresConnectionPool $pool, string $sql): mixed
{
    foreach ($pool->query($sql) as $row) {
        return reset($row);
    }

    return null;
}

uses()->beforeEach(function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('PostgreSQL not available on port '.test_env('FLEDGE_TEST_POSTGRES_PORT', 15432));
    }

    $this->recorder = new RecordingPostgresConnector;
    $this->pool = new PostgresConnectionPool(resetTestConfig(), maxConnections: 1, connector: $this->recorder);
})->afterEach(function () {
    $this->pool?->close();
});

it('sends no DISCARD ALL after clean statements', function () {
    $pool = $this->pool;

    $pid = inResetFiber(fn () => resetScalar($pool, 'SELECT pg_backend_pid()'));
    $warm = $this->recorder->count('DISCARD ALL');

    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    inResetFiber(fn () => iterator_to_array($pool->execute('SELECT $1::int AS v', [7])));
    inResetFiber(fn () => iterator_to_array($pool->prepare('SELECT $1::text AS v')->execute(['x'])));
    inResetFiber(fn () => resetScalar($pool, 'SHOW TimeZone'));
    $samePid = inResetFiber(fn () => resetScalar($pool, 'SELECT pg_backend_pid()'));

    // The fresh connection is reset once on its first checkout, never again.
    expect($warm)->toBe(1)
        ->and($this->recorder->count('DISCARD ALL'))->toBe(1)
        ->and($samePid)->toBe($pid);
});

it('resets a dirty session exactly once and the next fiber sees none of it', function (?string $setup, array $dirty, Closure $probe, mixed $leaked) {
    if ($setup !== null) {
        resetTestAdmin()->exec($setup);
    }

    // The probe detects the state: with resets off, the next fiber does see it.
    $leaky = new PostgresConnectionPool(resetTestConfig(), maxConnections: 1, resetConnections: false);

    try {
        inResetFiber(function () use ($leaky, $dirty): void {
            foreach ($dirty as $sql) {
                iterator_to_array($leaky->query($sql));
            }
        });
        expect(inResetFiber(fn () => $probe($leaky)))->toBe($leaked);
    } finally {
        $leaky->close();
    }

    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    inResetFiber(function () use ($pool, $dirty): void {
        foreach ($dirty as $sql) {
            iterator_to_array($pool->query($sql));
        }
    });
    $seen = inResetFiber(fn () => $probe($pool));

    expect($seen)->not->toBe($leaked)
        ->and($this->recorder->count('DISCARD ALL') - $before)->toBe(\count($dirty));
})->with([
    'SET' => [null, ["SET statement_timeout = '1234ms'"], fn ($pool) => resetScalar($pool, 'SHOW statement_timeout'), '1234ms'],
    'SET TIME ZONE' => [null, ["SET TIME ZONE 'Asia/Tokyo'"], fn ($pool) => resetScalar($pool, 'SHOW TimeZone'), 'Asia/Tokyo'],
    'SET search_path' => [null, ['SET search_path = pg_catalog'], fn ($pool) => resetScalar($pool, 'SHOW search_path'), 'pg_catalog'],
    'set_config()' => [null, ["SELECT set_config('statement_timeout', '4321ms', false)"], fn ($pool) => resetScalar($pool, 'SHOW statement_timeout'), '4321ms'],
    'temp table' => [null, ['CREATE TEMP TABLE _fledge_reset_temp (id int)'], fn ($pool) => resetScalar($pool, "SELECT to_regclass('_fledge_reset_temp')::text"), '_fledge_reset_temp'],
    'SELECT INTO TEMP' => [null, ['SELECT 1 AS a INTO TEMP _fledge_reset_into'], fn ($pool) => resetScalar($pool, "SELECT to_regclass('_fledge_reset_into')::text"), '_fledge_reset_into'],
    'advisory lock' => [null, ['SELECT pg_advisory_lock(424242)'], fn ($pool) => resetScalar($pool, "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = 424242 AND pid = pg_backend_pid()"), 1],
    'LISTEN' => [null, ['LISTEN _fledge_reset_channel'], fn ($pool) => resetScalar($pool, 'SELECT count(*) FROM pg_listening_channels()'), 1],
    'PREPARE' => [null, ['PREPARE _fledge_reset_stmt AS SELECT 1'], fn ($pool) => resetScalar($pool, "SELECT count(*) FROM pg_prepared_statements WHERE name = '_fledge_reset_stmt'"), 1],
    'held cursor' => [null, ['DECLARE _fledge_reset_cursor CURSOR WITH HOLD FOR SELECT 1'], fn ($pool) => resetScalar($pool, "SELECT count(*) FROM pg_cursors WHERE name = '_fledge_reset_cursor'"), 1],
    'DO block' => [null, ["DO \$\$ BEGIN PERFORM set_config('statement_timeout', '2345ms', false); END \$\$"], fn ($pool) => resetScalar($pool, 'SHOW statement_timeout'), '2345ms'],
    'user function' => [
        "CREATE OR REPLACE FUNCTION _fledge_reset_fn() RETURNS int LANGUAGE sql AS \$\$ SELECT length(set_config('statement_timeout', '3456ms', false)) \$\$",
        ['SELECT _fledge_reset_fn()'],
        fn ($pool) => resetScalar($pool, 'SHOW statement_timeout'),
        '3456ms',
    ],
    'nextval (lastval)' => [
        'CREATE TABLE IF NOT EXISTS _fledge_reset_serial (id serial PRIMARY KEY, v int)',
        ['INSERT INTO _fledge_reset_serial (v) VALUES (1)'],
        function ($pool) {
            try {
                return resetScalar($pool, 'SELECT lastval() > 0');
            } catch (Throwable) {
                return 'lastval undefined';
            }
        },
        true,
    ],
    'UPDATE firing a trigger' => [
        "CREATE TABLE IF NOT EXISTS _fledge_reset_trig (id int PRIMARY KEY, v int);
         INSERT INTO _fledge_reset_trig VALUES (1, 0) ON CONFLICT DO NOTHING;
         CREATE OR REPLACE FUNCTION _fledge_reset_trig_fn() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM set_config('app.x', 'leak', false); RETURN NULL; END \$\$;
         DROP TRIGGER IF EXISTS _fledge_reset_trig_t ON _fledge_reset_trig;
         CREATE TRIGGER _fledge_reset_trig_t AFTER UPDATE OR DELETE ON _fledge_reset_trig FOR EACH STATEMENT EXECUTE FUNCTION _fledge_reset_trig_fn()",
        ['UPDATE _fledge_reset_trig SET v = v + 1 WHERE id = 1'],
        fn ($pool) => resetScalar($pool, "SELECT coalesce(current_setting('app.x', true), '')"),
        'leak',
    ],
    'DELETE firing a trigger' => [
        "CREATE TABLE IF NOT EXISTS _fledge_reset_trig (id int PRIMARY KEY, v int);
         CREATE OR REPLACE FUNCTION _fledge_reset_trig_fn() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM set_config('app.x', 'leak', false); RETURN NULL; END \$\$;
         DROP TRIGGER IF EXISTS _fledge_reset_trig_t ON _fledge_reset_trig;
         CREATE TRIGGER _fledge_reset_trig_t AFTER UPDATE OR DELETE ON _fledge_reset_trig FOR EACH STATEMENT EXECUTE FUNCTION _fledge_reset_trig_fn()",
        ['DELETE FROM _fledge_reset_trig WHERE id = -1'],
        fn ($pool) => resetScalar($pool, "SELECT coalesce(current_setting('app.x', true), '')"),
        'leak',
    ],
    'two dirty statements' =>[null, ["SET statement_timeout = '1111ms'", 'CREATE TEMP TABLE _fledge_reset_two (id int)'], fn ($pool) => resetScalar($pool, 'SHOW statement_timeout'), '1111ms'],
]);

it('does not reset after SET LOCAL and an xact advisory lock in a committed transaction', function () {
    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    inResetFiber(function () use ($pool): void {
        $transaction = $pool->beginTransaction();
        iterator_to_array($transaction->query("SET LOCAL statement_timeout = '777ms'"));
        iterator_to_array($transaction->execute('SELECT pg_advisory_xact_lock($1)', [99]));
        iterator_to_array($transaction->prepare('SELECT $1::int AS v')->execute([1]));
        $transaction->commit();
    });

    $seen = inResetFiber(fn () => resetScalar($pool, 'SHOW statement_timeout'));
    $locks = inResetFiber(fn () => resetScalar($pool, "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));

    expect($seen)->not->toBe('777ms')
        ->and($locks)->toBe(0)
        ->and($this->recorder->count('DISCARD ALL') - $before)->toBe(0);
});

it('resets after a session SET inside a committed transaction', function () {
    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    inResetFiber(function () use ($pool): void {
        $transaction = $pool->beginTransaction();
        iterator_to_array($transaction->query("SET statement_timeout = '888ms'"));
        $transaction->commit();
    });

    expect(inResetFiber(fn () => resetScalar($pool, 'SHOW statement_timeout')))->not->toBe('888ms')
        ->and($this->recorder->count('DISCARD ALL') - $before)->toBe(1);
});

it('resets after a transaction in which a statement failed', function () {
    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    inResetFiber(function () use ($pool): void {
        $transaction = $pool->beginTransaction();
        $statement = $transaction->prepare('SELECT 1 / $1::int AS v');

        try {
            iterator_to_array($statement->execute([0]));
        } catch (Throwable) {
            // The transaction is aborted now.
        }

        $transaction->rollback();
    });

    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));

    expect($this->recorder->count('DISCARD ALL') - $before)->toBe(1);
});

it('resets after an abandoned transaction and the next fiber is not inside it', function () {
    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    $closed = new DeferredFuture;

    inResetFiber(function () use ($pool, $closed): void {
        $transaction = $pool->beginTransaction();
        $transaction->onClose(static fn () => $closed->complete());
        iterator_to_array($transaction->query('CREATE TEMP TABLE _fledge_reset_abandoned (id int)'));
        iterator_to_array($transaction->query("SET LOCAL statement_timeout = '999ms'"));
        unset($transaction); // Neither committed nor rolled back.
    });

    // The transaction closes once its rollback finished.
    $closed->getFuture()->await(new TimeoutCancellation(10, 'The abandoned transaction was never rolled back'));

    $state = inResetFiber(fn () => resetScalar($pool, "SELECT coalesce(to_regclass('_fledge_reset_abandoned')::text, '') || '|' || current_setting('statement_timeout') || '|' || (pg_current_xact_id_if_assigned() IS NULL)::text"));

    expect($state)->toBe('|0|true')
        ->and($this->recorder->count('DISCARD ALL') - $before)->toBe(1);
});

it('resets after a NOTIFY that failed inside a transaction', function () {
    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    $failed = inResetFiber(function () use ($pool): bool {
        $transaction = $pool->beginTransaction();

        try {
            // Over the 8000 byte payload limit: the server rejects it and aborts the transaction.
            $transaction->notify('_fledge_reset_notify', str_repeat('x', 9000));

            return false;
        } catch (Throwable) {
            return true;
        } finally {
            $transaction->rollback();
        }
    });

    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));

    expect($failed)->toBeTrue()
        ->and($this->recorder->count('DISCARD ALL') - $before)->toBe(1);
});

it('reports a lost connection without a PHP notice when the server ended an idle clean session', function () {
    $pool = $this->pool;
    $pid = inResetFiber(fn () => resetScalar($pool, 'SELECT pg_backend_pid()'));
    resetTestTerminate($pid);

    [$error, $notices] = resetRecordingErrors(fn () => inResetFiber(function () use ($pool): ?Throwable {
        try {
            resetScalar($pool, 'SELECT 1');

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }));

    $fresh = inResetFiber(fn () => resetScalar($pool, 'SELECT pg_backend_pid()'));

    expect($notices)->toBe([])
        ->and($error)->toBeInstanceOf(SqlConnectionException::class)
        ->and((new LostConnectionDetector)->causedByLostConnection($error))->toBeTrue()
        ->and($fresh)->not->toBe($pid);
});

it('reports a lost connection without a PHP notice through the PDO shim when the server ended an idle clean session', function () {
    $pdo = resetTestPdo($this->recorder);

    $select = function () use ($pdo): mixed {
        $statement = $pdo->prepare('SELECT pg_backend_pid() AS v');
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC)[0]['v'] ?? null;
    };

    try {
        $pid = inResetFiber($select);
        resetTestTerminate($pid);

        [$error, $notices] = resetRecordingErrors(fn () => inResetFiber(function () use ($select): ?Throwable {
            try {
                $select();

                return null;
            } catch (Throwable $e) {
                return $e;
            }
        }));

        $fresh = inResetFiber($select);

        expect($notices)->toBe([])
            ->and($error)->toBeInstanceOf(PDOException::class)
            ->and((new LostConnectionDetector)->causedByLostConnection($error))->toBeTrue()
            ->and($fresh)->not->toBe($pid);
    } finally {
        $pdo->close();
    }
});

it('never lets the next fiber run inside a transaction opened with a raw BEGIN', function () {
    $pool = $this->pool;
    $pid = inResetFiber(fn () => resetScalar($pool, 'SELECT pg_backend_pid()'));

    // One checkout: the explicit BEGIN leaves the transaction open after the query.
    inResetFiber(fn () => iterator_to_array($pool->query('BEGIN; CREATE TEMP TABLE _fledge_reset_begin (id int)')));

    // DISCARD ALL cannot run inside the open transaction, so the pool drops that session.
    $first = inResetFiber(function () use ($pool) {
        try {
            return resetScalar($pool, "SELECT to_regclass('_fledge_reset_begin')::text");
        } catch (Throwable $e) {
            return 'reset failed';
        }
    });

    $next = inResetFiber(fn () => resetScalar($pool, "SELECT pg_backend_pid()::text || '|' || coalesce(to_regclass('_fledge_reset_begin')::text, '')"));

    expect($first)->toBe('reset failed')
        ->and($next)->not->toBe($pid.'|')
        ->and($next)->toEndWith('|');
});

it('resets the listening connection when it goes back to the pool', function () {
    $pool = $this->pool;
    inResetFiber(fn () => resetScalar($pool, 'SELECT 1'));
    $before = $this->recorder->count('DISCARD ALL');

    $listener = $pool->listen('_fledge_reset_listen');

    // unlisten() returns once the server ran UNLISTEN; the pool has one connection, so the
    // next checkout waits for the listener to hand it back.
    $listener->unlisten();

    expect(inResetFiber(fn () => resetScalar($pool, 'SELECT count(*) FROM pg_listening_channels()')))->toBe(0)
        ->and($this->recorder->count('DISCARD ALL') - $before)->toBe(1);
});

it('resets only dirty sessions through the PDO shim', function () {
    $recorder = $this->recorder;
    $connector = new class($recorder) extends FledgePostgresConnector
    {
        public function __construct(private RecordingPostgresConnector $recorder) {}

        protected function createPool(PostgresConfig $config, array $appConfig): PostgresConnectionPool
        {
            return new PostgresConnectionPool($config, 1, connector: $this->recorder);
        }
    };

    $pdo = $connector->connect(postgresConfig());
    expect($pdo)->toBeInstanceOf(FledgePostgresPdo::class);

    try {
        $select = function () use ($pdo): mixed {
            $statement = $pdo->prepare('SELECT current_setting(?) AS v');
            $statement->execute(['statement_timeout']);

            return $statement->fetchAll(PDO::FETCH_ASSOC)[0]['v'] ?? null;
        };

        inResetFiber($select);
        $before = $recorder->count('DISCARD ALL');

        inResetFiber($select);
        inResetFiber(function () use ($pdo): void {
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL statement_timeout = '555ms'");
            $pdo->commit();
        });
        $clean = inResetFiber($select);
        $cleanResets = $recorder->count('DISCARD ALL') - $before;

        inResetFiber(fn () => $pdo->exec("SET statement_timeout = '666ms'"));
        $dirty = inResetFiber($select);

        expect($clean)->not->toBe('555ms')
            ->and($cleanResets)->toBe(0)
            ->and($dirty)->not->toBe('666ms')
            ->and($recorder->count('DISCARD ALL') - $before)->toBe(1);
    } finally {
        $pdo->close();
    }
});
