<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlException;
use Fledge\Async\DeferredFuture;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;

use function Fledge\Async\async;

/*
 * pool_wait_timeout of the amphp-style MySQL pool: a caller waiting on a full pool gives up after the
 * configured time with a connection error, without leaving a connection or a turn behind. Time passes
 * through the timeout under test and through SELECT SLEEP() on the held connection, never through delay().
 */

function waitTimeoutPool(?float $waitTimeout): MysqlConnectionPool
{
    if (! mysqlAvailable()) {
        test()->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
    }

    $c = mysqlConfig();
    $config = new MysqlConfig($c['host'], $c['port'], $c['username'], $c['password'], $c['database']);

    return $waitTimeout === null
        ? new MysqlConnectionPool($config, 1)
        : new MysqlConnectionPool($config, 1, waitTimeout: $waitTimeout);
}

/** Fails the test instead of hanging for ever when a waiter is never woken. */
function waitTimeoutWatchdog(Closure $body): mixed
{
    if (! function_exists('pcntl_alarm')) {
        return $body();
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): never {
        throw new RuntimeException('watchdog: the pool did not finish within 10 seconds (hang)');
    });
    pcntl_alarm(10);

    try {
        return $body();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}

/** Starts a fiber that leases a connection, and returns once that fiber is about to ask for it. */
function waitTimeoutLease(MysqlConnectionPool $pool): Fledge\Async\Future
{
    $started = new DeferredFuture;

    $lease = async(function () use ($pool, $started) {
        $started->complete();

        return $pool->leaseConnection();
    });

    $started->getFuture()->await();

    return $lease;
}

it('makes a waiter on a full pool give up with a connection error', function () {
    $pool = waitTimeoutPool(0.2);

    try {
        [$error, $count, $idle, $awaiting] = waitTimeoutWatchdog(function () use ($pool): array {
            $held = $pool->leaseConnection();
            $waiter = waitTimeoutLease($pool);

            try {
                $waiter->await();
                $error = null;
            } catch (SqlException $e) {
                $error = $e;
            }

            $state = [$error, $pool->getConnectionCount(), $pool->getIdleConnectionCount(), $pool->isAwaitingConnection()];
            $pool->releaseConnection($held);

            return $state;
        });
    } finally {
        $pool->close();
    }

    expect($error)->toBeInstanceOf(SqlException::class)
        ->and($error)->not->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getCode())->toBe(2002)
        ->and($error->getMessage())->toContain('Timed out after 0.2s waiting for a connection from the pool')
        ->and($count)->toBe(1)
        ->and($idle)->toBe(0)
        ->and($awaiting)->toBeFalse();
});

it('serves the next waiter with a connection released after a timeout', function () {
    $pool = waitTimeoutPool(0.2);

    try {
        [$timedOut, $served, $held] = waitTimeoutWatchdog(function () use ($pool): array {
            $held = $pool->leaseConnection();

            try {
                waitTimeoutLease($pool)->await();
                $timedOut = false;
            } catch (SqlException) {
                $timedOut = true;
            }

            $next = waitTimeoutLease($pool);
            expect($pool->isAwaitingConnection())->toBeTrue();

            $pool->releaseConnection($held);
            $served = $next->await();
            $served->query('SELECT 1');
            $pool->releaseConnection($served);

            return [$timedOut, $served, $held];
        });
    } finally {
        $pool->close();
    }

    expect($timedOut)->toBeTrue()
        ->and($served)->toBe($held)
        ->and($pool->getConnectionCount())->toBe(1);
});

it('wakes the callers queued behind a waiter that timed out', function () {
    $pool = waitTimeoutPool(0.5);

    try {
        [$ownerError, $order] = waitTimeoutWatchdog(function () use ($pool): array {
            $held = $pool->leaseConnection();
            $owner = waitTimeoutLease($pool);

            // The queued callers start 0.25s after the owner, so their own deadlines are well after its one.
            $held->query('SELECT SLEEP(0.25)');

            $order = [];
            $queued = [];

            foreach (['first', 'second'] as $name) {
                $queued[] = async(function () use ($pool, $name, &$order): void {
                    $connection = $pool->leaseConnection();
                    $order[] = $name;
                    $connection->query('SELECT 1');
                    $pool->releaseConnection($connection);
                });
            }

            try {
                $owner->await();
                $ownerError = null;
            } catch (SqlException $e) {
                $ownerError = $e;
            }

            // Released right after the owner gave up, about 0.25s before the queued callers would.
            $pool->releaseConnection($held);

            foreach ($queued as $future) {
                $future->await();
            }

            return [$ownerError, $order];
        });
    } finally {
        $pool->close();
    }

    expect($ownerError)->toBeInstanceOf(SqlException::class)
        ->and($order)->toBe(['first', 'second'])
        ->and($pool->getConnectionCount())->toBe(1);
});

it('keeps waiting without a timeout', function () {
    $pool = waitTimeoutPool(null);

    try {
        [$stillWaiting, $served, $held] = waitTimeoutWatchdog(function () use ($pool): array {
            $held = $pool->leaseConnection();
            $waiter = waitTimeoutLease($pool);

            $held->query('SELECT SLEEP(0.3)');
            $stillWaiting = ! $waiter->isComplete() && $pool->isAwaitingConnection();

            $pool->releaseConnection($held);
            $served = $waiter->await();
            $pool->releaseConnection($served);

            return [$stillWaiting, $served, $held];
        });
    } finally {
        $pool->close();
    }

    expect($pool->getWaitTimeout())->toBeNull()
        ->and($stillWaiting)->toBeTrue()
        ->and($served)->toBe($held);
});

it('reads pool_wait_timeout and surfaces the timeout as a query error Laravel does not retry', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
    }

    $config = ['pool_size' => 1, 'pool_wait_timeout' => 0.2] + mysqlConfig();
    $pdo = (new FledgeMySqlConnector)->connect($config);
    $connection = new FledgeMySqlConnection($pdo, $config['database'], '', $config + ['name' => 'fledge-mysql-wait', 'driver' => 'fledge-mysql']);

    try {
        $error = waitTimeoutWatchdog(function () use ($connection) {
            // A changed session pins the only connection to the main context, outside any transaction.
            $connection->statement('SET @fledge_wait_timeout = 1');

            try {
                async(fn () => $connection->select('SELECT 2'))->await();

                return null;
            } catch (Throwable $e) {
                return $e;
            }
        });
    } finally {
        $pdo->close();
    }

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getMessage())->toStartWith('SQLSTATE[HY000]: General error: 2002 Timed out after 0.2s waiting for a connection from the pool')
        ->and($error->errorInfo)->toBe(['HY000', 2002, $error->getPrevious()->errorInfo[2]])
        ->and((new LostConnectionDetector)->causedByLostConnection($error))->toBeFalse();
});
