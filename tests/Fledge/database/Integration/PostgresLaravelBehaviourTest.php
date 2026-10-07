<?php

use Fledge\Async\DeferredFuture;
use Fledge\Fiber\Database\Connections\FledgePostgresConnection;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

class FledgeBehaviourPostgresUser extends Model
{
    protected $table = '_fledge_behaviour_pg_users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Build a Laravel connection on the fledge-pgsql driver.
 */
function fledgePostgresBehaviourConnection(array $extra = []): FledgePostgresConnection
{
    $config = $extra + postgresConfig();

    $connection = new FledgePostgresConnection(
        (new FledgePostgresConnector)->connect($config),
        $config['database'],
        '',
        $config + ['name' => 'fledge-pgsql', 'driver' => 'fledge-pgsql'],
    );

    $connection->setReconnector(function (FledgePostgresConnection $connection) use ($config): void {
        $connection->setPdo((new FledgePostgresConnector)->connect($config));
    });

    return $connection;
}

/**
 * Open a blocking real pdo_pgsql connection to the same server.
 */
function realPostgresPdo(): PDO
{
    $config = postgresConfig();

    return new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

uses()->beforeEach(function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('PostgreSQL not available on port '.test_env('FLEDGE_TEST_POSTGRES_PORT', 15432));
    }

    $this->admin = realPostgresPdo();
    $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_pg_users');
    $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_pg_counters');
    $this->admin->exec('CREATE TABLE _fledge_behaviour_pg_users (id SERIAL PRIMARY KEY, email VARCHAR(191) NOT NULL, name VARCHAR(191) NULL, CONSTRAINT _fledge_behaviour_pg_users_email_unique UNIQUE (email))');
    $this->admin->exec('CREATE TABLE _fledge_behaviour_pg_counters (id INT PRIMARY KEY, n INT NOT NULL DEFAULT 0)');
    $this->admin->exec('INSERT INTO _fledge_behaviour_pg_counters (id, n) VALUES (1, 0), (2, 0)');

    $this->connections = [];
})->afterEach(function () {
    foreach ($this->connections ?? [] as $connection) {
        $connection->getPdo()?->close();
    }

    if (isset($this->holder)) {
        $this->holder->inTransaction() && $this->holder->rollBack();
        $this->holder = null;
    }

    if (isset($this->admin)) {
        $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_pg_users');
        $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_pg_counters');
        $this->admin = null;
    }

    Model::unsetConnectionResolver();
});

it('throws UniqueConstraintViolationException on a duplicate insert', function () {
    $this->connections[] = $connection = fledgePostgresBehaviourConnection();

    $connection->table('_fledge_behaviour_pg_users')->insert(['email' => 'a@example.com']);

    try {
        $connection->table('_fledge_behaviour_pg_users')->insert(['email' => 'a@example.com']);
        $this->fail('Expected a UniqueConstraintViolationException');
    } catch (UniqueConstraintViolationException $e) {
        expect($e->index)->toBe('_fledge_behaviour_pg_users_email_unique')
            ->and($e->columns)->toBe(['email'])
            ->and($e->getCode())->toBe('23505')
            ->and($e->errorInfo[0])->toBe('23505')
            ->and($e->getMessage())->toContain('duplicate key value violates unique constraint')
            ->and($e->getPrevious())->toBeInstanceOf(PDOException::class);
    }
});

it('returns the existing row from createOrFirst', function () {
    $this->connections[] = $connection = fledgePostgresBehaviourConnection();

    Model::setConnectionResolver(new ConnectionResolver(['fledge-pgsql' => $connection]));
    Model::getConnectionResolver()->setDefaultConnection('fledge-pgsql');

    $original = FledgeBehaviourPostgresUser::create(['email' => 'b@example.com', 'name' => 'Original']);

    $found = FledgeBehaviourPostgresUser::createOrFirst(['email' => 'b@example.com'], ['name' => 'Second']);

    expect($found->wasRecentlyCreated)->toBeFalse()
        ->and($found->id)->toEqual($original->id)
        ->and($found->name)->toBe('Original')
        ->and($connection->table('_fledge_behaviour_pg_users')->count())->toBe(1);
});

it('retries a real 40P01 deadlock with DB::transaction attempts and succeeds', function () {
    // deadlock_timeout keeps the test fast; lock_timeout is only a safety net so a
    // missed deadlock fails after 5s instead of hanging.
    $options = ['server_options' => ['deadlock_timeout' => '200ms', 'lock_timeout' => '5s']];
    $this->connections[] = $a = fledgePostgresBehaviourConnection($options);
    $this->connections[] = $b = fledgePostgresBehaviourConnection($options);

    $aLocked = new DeferredFuture;
    $bLocked = new DeferredFuture;
    $attempts = ['a' => 0, 'b' => 0];
    $errors = [];

    $run = function (string $name, FledgePostgresConnection $connection, int $first, int $second, DeferredFuture $signal, DeferredFuture $wait) use (&$attempts, &$errors) {
        return $connection->transaction(function (FledgePostgresConnection $connection) use ($name, $first, $second, $signal, $wait, &$attempts, &$errors) {
            $attempts[$name]++;

            try {
                if ($name === 'b') {
                    $wait->getFuture()->await();
                }

                $connection->update('UPDATE _fledge_behaviour_pg_counters SET n = n + 1 WHERE id = ?', [$first]);

                if (! $signal->isComplete()) {
                    $signal->complete();
                }

                if ($name === 'a') {
                    $wait->getFuture()->await();
                }

                $connection->update('UPDATE _fledge_behaviour_pg_counters SET n = n + 1 WHERE id = ?', [$second]);
            } catch (Throwable $e) {
                $errors[] = $e;

                throw $e;
            }

            return $name;
        }, attempts: 3);
    };

    $results = await([
        async(fn () => $run('a', $a, 1, 2, $aLocked, $bLocked)),
        async(fn () => $run('b', $b, 2, 1, $bLocked, $aLocked)),
    ]);

    ksort($results);

    expect($results)->toBe(['a', 'b'])
        ->and($attempts['a'] + $attempts['b'])->toBe(3)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toBeInstanceOf(QueryException::class)
        ->and($errors[0]->getCode())->toBe('40P01')
        ->and($errors[0]->getMessage())->toContain('deadlock detected')
        ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($errors[0]))->toBeTrue()
        ->and($a->table('_fledge_behaviour_pg_counters')->orderBy('id')->pluck('n')->all())->toBe([2, 2]);
});

it('treats a 55P03 lock timeout like stock Laravel does, as not a concurrency error', function () {
    $this->connections[] = $connection = fledgePostgresBehaviourConnection([
        'server_options' => ['lock_timeout' => '1s'],
    ]);

    $this->holder = realPostgresPdo();
    $this->holder->beginTransaction();
    $this->holder->exec('UPDATE _fledge_behaviour_pg_counters SET n = n + 1 WHERE id = 1');

    $attempts = 0;

    try {
        $connection->transaction(function (FledgePostgresConnection $connection) use (&$attempts) {
            $attempts++;
            $connection->update('UPDATE _fledge_behaviour_pg_counters SET n = n + 10 WHERE id = 1');
        }, attempts: 2);
        $this->fail('Expected a lock timeout');
    } catch (QueryException $e) {
        // "canceling statement due to lock timeout" (55P03) matches neither code 40001
        // nor any ConcurrencyErrorDetector substring, so stock Laravel does not retry.
        expect($e->getCode())->toBe('55P03')
            ->and($e->errorInfo[0])->toBe('55P03')
            ->and($e->getMessage())->toContain('canceling statement due to lock timeout')
            ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($e))->toBeFalse()
            ->and($attempts)->toBe(1)
            ->and($connection->transactionLevel())->toBe(0);
    }
});

it('runs the query on a fresh connection after the server terminated it outside a transaction', function () {
    $this->connections[] = $connection = fledgePostgresBehaviourConnection(['pool_size' => 1]);

    $killed = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;

    realPostgresPdo()->query('SELECT pg_terminate_backend('.(int) $killed.')')->fetchAll();

    $fresh = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;

    expect($fresh)->not->toEqual($killed)
        ->and($connection->table('_fledge_behaviour_pg_counters')->count())->toBe(2);
});
