<?php

use Fledge\Async\DeferredFuture;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Pdo\Mysql;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

class FledgeBehaviourMysqlUser extends Model
{
    protected $table = '_fledge_behaviour_my_users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Build a Laravel connection on the fledge-mysql driver, or on the native
 * driver (fledge-mysql-native, needs fiberio) for $driver 'native'.
 */
function fledgeMysqlBehaviourConnection(array $extra = [], string $driver = 'fledge'): Connection
{
    if ($driver === 'native') {
        // Same connection name as the fledge side: Eloquent models re-resolve their connection by name.
        return nativeDriverConnection('mysql', $extra + ['name' => 'fledge-mysql']);
    }

    $config = $extra + mysqlConfig();

    $connection = new FledgeMySqlConnection(
        (new FledgeMySqlConnector)->connect($config),
        $config['database'],
        '',
        $config + ['name' => 'fledge-mysql', 'driver' => 'fledge-mysql'],
    );

    $connection->setReconnector(function (FledgeMySqlConnection $connection) use ($config): void {
        $connection->setPdo((new FledgeMySqlConnector)->connect($config));
    });

    return $connection;
}

/**
 * Open a blocking real pdo_mysql connection to the same server.
 */
function realMysqlPdo(): PDO
{
    $config = mysqlConfig();

    return new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

uses()->beforeEach(function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
    }

    $this->admin = realMysqlPdo();
    $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_my_users');
    $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_my_counters');
    $this->admin->exec('CREATE TABLE _fledge_behaviour_my_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(191) NOT NULL, name VARCHAR(191) NULL, UNIQUE KEY _fledge_behaviour_my_users_email_unique (email)) ENGINE=InnoDB');
    $this->admin->exec('CREATE TABLE _fledge_behaviour_my_counters (id INT PRIMARY KEY, n INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $this->admin->exec('INSERT INTO _fledge_behaviour_my_counters (id, n) VALUES (1, 0), (2, 0)');

    $this->connections = [];
})->afterEach(function () {
    foreach ($this->connections ?? [] as $connection) {
        $connection instanceof FledgeMySqlConnection ? $connection->getPdo()?->close() : $connection->disconnect();
    }

    nativeDriverReset();

    if (isset($this->holder)) {
        $this->holder->inTransaction() && $this->holder->rollBack();
        $this->holder = null;
    }

    if (isset($this->admin)) {
        $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_my_users');
        $this->admin->exec('DROP TABLE IF EXISTS _fledge_behaviour_my_counters');
        $this->admin = null;
    }

    Model::unsetConnectionResolver();
});

it('throws UniqueConstraintViolationException on a duplicate insert', function (string $driver) {
    $this->connections[] = $connection = fledgeMysqlBehaviourConnection(driver: $driver);

    $connection->table('_fledge_behaviour_my_users')->insert(['email' => 'a@example.com']);

    try {
        $connection->table('_fledge_behaviour_my_users')->insert(['email' => 'a@example.com']);
        $this->fail('Expected a UniqueConstraintViolationException');
    } catch (UniqueConstraintViolationException $e) {
        expect($e->index)->toBe('_fledge_behaviour_my_users_email_unique')
            ->and($e->getCode())->toBe('23000')
            ->and($e->errorInfo[0])->toBe('23000')
            ->and($e->errorInfo[1])->toBe(1062)
            ->and($e->getMessage())->toContain('Integrity constraint violation: 1062 Duplicate entry')
            ->and($e->getPrevious())->toBeInstanceOf(PDOException::class);
    }
})->with(['fledge', 'native']);

it('returns the existing row from createOrFirst', function (string $driver) {
    $this->connections[] = $connection = fledgeMysqlBehaviourConnection(driver: $driver);

    Model::setConnectionResolver(new ConnectionResolver(['fledge-mysql' => $connection]));
    Model::getConnectionResolver()->setDefaultConnection('fledge-mysql');

    $original = FledgeBehaviourMysqlUser::create(['email' => 'b@example.com', 'name' => 'Original']);

    $found = FledgeBehaviourMysqlUser::createOrFirst(['email' => 'b@example.com'], ['name' => 'Second']);

    expect($found->wasRecentlyCreated)->toBeFalse()
        ->and($found->getConnection())->toBe($connection)
        ->and($found->id)->toEqual($original->id)
        ->and($found->name)->toBe('Original')
        ->and($connection->table('_fledge_behaviour_my_users')->count())->toBe(1);
})->with(['fledge', 'native']);

it('retries a real 1213 deadlock with DB::transaction attempts and succeeds', function (string $driver) {
    // Safety net only: a missed deadlock surfaces as 1205 after 5s instead of hanging.
    $options = ['options' => [Mysql::ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout = 5']];
    $this->connections[] = $a = fledgeMysqlBehaviourConnection($options, $driver);
    $this->connections[] = $b = fledgeMysqlBehaviourConnection($options, $driver);

    $aLocked = new DeferredFuture;
    $bLocked = new DeferredFuture;
    $attempts = ['a' => 0, 'b' => 0];
    $errors = [];

    $run = function (string $name, Connection $connection, int $first, int $second, ?DeferredFuture $signal, ?DeferredFuture $wait) use (&$attempts, &$errors) {
        return $connection->transaction(function (Connection $connection) use ($name, $first, $second, $signal, $wait, &$attempts, &$errors) {
            $attempts[$name]++;

            try {
                if ($name === 'b') {
                    $wait->getFuture()->await();
                }

                $connection->update('UPDATE _fledge_behaviour_my_counters SET n = n + 1 WHERE id = ?', [$first]);

                if (! $signal->isComplete()) {
                    $signal->complete();
                }

                if ($name === 'a') {
                    $wait->getFuture()->await();
                }

                $connection->update('UPDATE _fledge_behaviour_my_counters SET n = n + 1 WHERE id = ?', [$second]);
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
        ->and($errors[0]->errorInfo[1])->toBe(1213)
        ->and($errors[0]->getMessage())->toContain('Deadlock found when trying to get lock')
        ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($errors[0]))->toBeTrue()
        ->and($a->table('_fledge_behaviour_my_counters')->orderBy('id')->pluck('n')->all())->toBe([2, 2]);
})->with(['fledge', 'native']);

it('detects a 1205 lock wait timeout as a concurrency error like stock Laravel', function (string $driver) {
    $this->connections[] = $connection = fledgeMysqlBehaviourConnection([
        'options' => [Mysql::ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout = 1'],
    ], $driver);

    $this->holder = realMysqlPdo();
    $this->holder->beginTransaction();
    $this->holder->exec('UPDATE _fledge_behaviour_my_counters SET n = n + 1 WHERE id = 1');

    $attempts = 0;

    try {
        $connection->transaction(function (Connection $connection) use (&$attempts) {
            $attempts++;
            $connection->update('UPDATE _fledge_behaviour_my_counters SET n = n + 10 WHERE id = 1');
        }, attempts: 2);
        $this->fail('Expected a lock wait timeout');
    } catch (QueryException $e) {
        // Stock Laravel matches "Lock wait timeout exceeded; try restarting transaction",
        // so the transaction is retried until the attempts run out.
        expect($e->getCode())->toBe('HY000')
            ->and($e->errorInfo[1])->toBe(1205)
            ->and($e->getMessage())->toContain('Lock wait timeout exceeded; try restarting transaction')
            ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($e))->toBeTrue()
            ->and($attempts)->toBe(2)
            ->and($connection->transactionLevel())->toBe(0);
    }
})->with(['fledge', 'native']);

it('runs the query on a fresh connection after the server killed it outside a transaction', function (string $driver) {
    $this->connections[] = $connection = fledgeMysqlBehaviourConnection(['pool_size' => 1], $driver);

    $killed = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;

    realMysqlPdo()->exec('KILL '.(int) $killed);

    $fresh = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;

    expect($fresh)->not->toEqual($killed)
        ->and($connection->table('_fledge_behaviour_my_counters')->count())->toBe(2);
})->with(['fledge', 'native']);
