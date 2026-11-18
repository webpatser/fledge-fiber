<?php

use Fledge\Async\Cancellation;
use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresConnectionPool;
use Fledge\Async\Database\SqlCommonConnectionPool;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnection;
use Fledge\Async\Database\SqlConnector;

use function Fledge\Async\async;
use function Fledge\Async\Database\Mysql\mysqlConnector;
use function Fledge\Async\Database\Postgres\postgresConnector;
use function Fledge\Async\delay;

/*
 * A pool of one connection under contention: the caller that waited first must get the connection first,
 * and a caller that asks again right after handing a connection back (or right as a connect finishes)
 * must wait its turn instead of spinning on the completed future of the waiter ahead of it.
 */

/** Runs the real connector, then the hook once, right before the connect future completes. */
function fairnessConnector(SqlConnector $inner, ?Closure $afterFirstConnect = null): SqlConnector
{
    return new class($inner, $afterFirstConnect) implements SqlConnector
    {
        public function __construct(private SqlConnector $inner, private ?Closure $hook) {}

        public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqlConnection
        {
            $connection = $this->inner->connect($config, $cancellation);

            if ($this->hook !== null) {
                $hook = $this->hook;
                $this->hook = null;
                $hook();
            }

            return $connection;
        }
    };
}

function fairnessPool(string $driver, ?Closure $afterFirstConnect = null): SqlCommonConnectionPool
{
    if ($driver === 'mysql') {
        if (! mysqlAvailable()) {
            test()->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
        }

        $c = mysqlConfig();
        $config = new MysqlConfig($c['host'], $c['port'], $c['username'], $c['password'], $c['database']);

        return new MysqlConnectionPool($config, 1, connector: fairnessConnector(mysqlConnector(), $afterFirstConnect));
    }

    if (! postgresAvailable()) {
        test()->markTestSkipped('PostgreSQL not available on port '.test_env('FLEDGE_TEST_POSTGRES_PORT', 15432));
    }

    $c = postgresConfig();
    $config = new PostgresConfig($c['host'], $c['port'], $c['username'], $c['password'], $c['database']);

    return new PostgresConnectionPool($config, 1, connector: fairnessConnector(postgresConnector(), $afterFirstConnect));
}

/** Fails the test instead of spinning for ever when a loop never yields. */
function fairnessWatchdog(Closure $body): mixed
{
    if (! function_exists('pcntl_alarm')) {
        return $body();
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): never {
        throw new RuntimeException('watchdog: the pool did not finish within 10 seconds (livelock)');
    });
    pcntl_alarm(10);

    try {
        return $body();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}

it('hands a released connection to the waiter before a caller that asks again at once', function (string $driver) {
    $pool = fairnessPool($driver);
    $order = [];

    try {
        fairnessWatchdog(function () use ($pool, &$order): void {
            $holder = async(function () use ($pool, &$order): void {
                $connection = $pool->leaseConnection();

                $spins = 0;
                while (! $pool->isAwaitingConnection()) {
                    if (++$spins > 2000) {
                        throw new RuntimeException('the second caller never started waiting');
                    }
                    delay(0.001);
                }

                // Hand the connection back with a waiter present, then ask again without yielding.
                $pool->releaseConnection($connection);
                $connection = $pool->leaseConnection();
                $order[] = 'holder';
                $connection->query('SELECT 1');
                $pool->releaseConnection($connection);
            });

            $waiter = async(function () use ($pool, &$order): void {
                $connection = $pool->leaseConnection();
                $order[] = 'waiter';
                $connection->query('SELECT 1');
                $pool->releaseConnection($connection);
            });

            $holder->await();
            $waiter->await();
        });
    } finally {
        $pool->close();
    }

    expect($order)->toBe(['waiter', 'holder'])
        ->and($pool->getConnectionCount())->toBe(1);
})->with(['mysql', 'postgres']);

it('makes a caller arriving as a connect completes wait for the connecting caller', function (string $driver) {
    $order = [];
    $pool = null;
    $late = null;

    // The late caller starts right before the connect future completes, so it runs while that future is
    // complete but the connecting fiber has not resumed yet.
    $pool = fairnessPool($driver, function () use (&$pool, &$late, &$order): void {
        $late = async(function () use (&$pool, &$order): void {
            $connection = $pool->leaseConnection();
            $order[] = 'late';
            $pool->releaseConnection($connection);
        });
    });

    try {
        fairnessWatchdog(function () use ($pool, &$late, &$order): void {
            $connecting = async(function () use ($pool, &$order): void {
                $connection = $pool->leaseConnection();
                $order[] = 'connecting';
                $connection->query('SELECT 1');
                $pool->releaseConnection($connection);
            });

            $connecting->await();
            $late->await();
        });
    } finally {
        $pool->close();
    }

    expect($order)->toBe(['connecting', 'late'])
        ->and($pool->getConnectionCount())->toBe(1);
})->with(['mysql', 'postgres']);
