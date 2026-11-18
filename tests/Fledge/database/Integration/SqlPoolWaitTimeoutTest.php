<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Async\Cancellation;
use Fledge\Async\Database\Postgres\PgSqlConnection;
use Fledge\Async\Database\Postgres\PostgresConfig;
use Fledge\Async\Database\Postgres\PostgresConnection;
use Fledge\Async\Database\Postgres\PostgresConnectionPool;
use Fledge\Async\Database\Postgres\PqConnection;
use Fledge\Async\Database\SqlConfig;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlConnector;
use Fledge\Async\Database\SqlException;
use Fledge\Async\DeferredFuture;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connections\FledgePostgresConnection;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;

use function Fledge\Async\async;
use function Fledge\Async\Stream\listen;

/*
 * pool_wait_timeout of the amphp-style MySQL and Postgres pools: a caller waiting on a full pool gives up
 * after the configured time with a connection error, without leaving a connection or a turn behind. Time
 * passes through the timeout under test, through SELECT SLEEP() on the held connection, or through a socket
 * that never answers, never through delay().
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
function waitTimeoutLease(Fledge\Async\Database\SqlCommonConnectionPool $pool): Fledge\Async\Future
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

it('gives up on a connect that outlives the wait timeout', function () {
    // Accepts the TCP connection and never sends the MySQL greeting, so the connect hangs.
    $server = listen('127.0.0.1:0');
    $accepted = new ArrayObject;

    (void) async(function () use ($server, $accepted): void {
        while (($socket = $server->accept()) !== null) {
            $accepted[] = $socket;
        }
    });

    $config = new MysqlConfig('127.0.0.1', $server->getAddress()->getPort(), 'fledge', 'fledge', 'fledge_test');
    $pool = new MysqlConnectionPool($config, 1, waitTimeout: 0.2);

    try {
        [$error, $count, $awaiting] = waitTimeoutWatchdog(function () use ($pool): array {
            try {
                $pool->leaseConnection();
                $error = null;
            } catch (SqlException $e) {
                $error = $e;
            }

            return [$error, $pool->getConnectionCount(), $pool->isAwaitingConnection()];
        });
    } finally {
        $pool->close();
        $server->close();

        // The connect still running in the background fails on EOF instead of keeping the loop alive.
        foreach ($accepted as $socket) {
            $socket->close();
        }
    }

    expect($error)->toBeInstanceOf(SqlException::class)
        ->and($error)->not->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getCode())->toBe(2002)
        ->and($error->getMessage())->toBe('Timed out after 0.2s waiting for a connection from the pool (0 of 1 connections in use)')
        ->and($accepted)->toHaveCount(1)
        ->and($count)->toBe(0)
        ->and($awaiting)->toBeFalse();
});

it('fails the callers in a timed wait at once when the pool closes', function () {
    $pool = waitTimeoutPool(5.0);

    [$awaiting, $errors, $elapsed] = waitTimeoutWatchdog(function () use ($pool): array {
        $held = $pool->leaseConnection();
        $owner = waitTimeoutLease($pool);
        $queued = waitTimeoutLease($pool);

        $held->query('SELECT SLEEP(0.1)');
        $awaiting = $pool->isAwaitingConnection();

        $start = hrtime(true);
        $pool->close();

        $errors = [];

        foreach ([$owner, $queued] as $waiter) {
            try {
                $waiter->await();
                $errors[] = null;
            } catch (SqlException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $elapsed = (hrtime(true) - $start) / 1e9;
        $pool->releaseConnection($held);

        return [$awaiting, $errors, $elapsed];
    });

    // Far below the 5 s wait timeout: close() wakes the waiters instead of their deadlines.
    expect($awaiting)->toBeTrue()
        ->and($errors)->toBe(['Connection pool closed', 'Connection pool closed'])
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($pool->isAwaitingConnection())->toBeFalse()
        ->and($pool->getConnectionCount())->toBe(0);
});

it('defaults pool_wait_timeout to 30 seconds, with 0 or an empty string for no limit', function (string $connector, mixed $value, ?float $expected) {
    $config = ['host' => '127.0.0.1', 'port' => 1, 'database' => 'fledge_test', 'username' => 'fledge', 'password' => 'fledge'];

    if ($value !== 'unset') {
        $config['pool_wait_timeout'] = $value;
    }

    // The pools connect lazily, so nothing reaches port 1.
    $pdo = (new $connector)->connect($config);

    try {
        expect((fn () => $this->pool)->call($pdo)->getWaitTimeout())->toBe($expected);
    } finally {
        $pdo->close();
    }
})->with([
    'mysql' => FledgeMySqlConnector::class,
    'pgsql' => FledgePostgresConnector::class,
])->with([
    'unset' => ['unset', 30.0],
    'null (env unset)' => [null, 30.0],
    'zero' => [0, null],
    'zero string' => ['0', null],
    'empty string' => ['', null],
    'float' => [1.5, 1.5],
    'numeric string' => ['2', 2.0],
]);

function pgWaitTimeoutConfig(?int $port = null): PostgresConfig
{
    if (! postgresAvailable()) {
        test()->markTestSkipped('Postgres not available on port '.test_env('FLEDGE_TEST_POSTGRES_PORT', 15432));
    }

    $c = postgresConfig();

    return new PostgresConfig($c['host'], $port ?? $c['port'], $c['username'], $c['password'], $c['database']);
}

/**
 * A TCP relay to the Postgres server whose client-to-server direction can be held: while held, the client's
 * bytes are dropped, so a statement sent through it never reaches the server. It runs in a child process:
 * a client that blocks inside libpq blocks this whole process (loop, timers and signals included), and an
 * in-process relay would then never answer, so the test would hang instead of fail. The relay also ends a
 * session whose bytes it held for 3 s, as a dead server's TCP eventually does, which ends such a block.
 */
final class WaitTimeoutPgRelay
{
    private const SCRIPT = <<<'PHP'
        [, $host, $port] = $argv;
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr) or exit(2);
        fwrite(STDOUT, 'port '.substr(strrchr(stream_socket_get_name($server, false), ':'), 1)."\n");
        $hold = false;
        $pairs = [];
        $end = microtime(true) + 60;
        while (microtime(true) < $end) {
            $read = [STDIN, $server];
            foreach ($pairs as [$client, $upstream]) {
                $read[] = $client;
                $read[] = $upstream;
            }
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 50000) === false) {
                break;
            }
            foreach ($read as $stream) {
                if ($stream === STDIN) {
                    if (($line = fgets(STDIN)) === false) {
                        exit(0);
                    }
                    $hold = trim($line) === 'hold';
                    fwrite(STDOUT, "ok\n");
                } elseif ($stream === $server) {
                    $client = @stream_socket_accept($server, 0);
                    $upstream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
                    if ($client && $upstream) {
                        $pairs[(int) $client] = [$client, $upstream, null];
                    } elseif ($client) {
                        fclose($client);
                    }
                } else {
                    foreach ($pairs as $id => [$client, $upstream]) {
                        if ($stream !== $client && $stream !== $upstream) {
                            continue;
                        }
                        $data = fread($stream, 65536);
                        if ($data === false || $data === '') {
                            fclose($client);
                            fclose($upstream);
                            unset($pairs[$id]);
                        } elseif ($stream === $upstream) {
                            fwrite($client, $data);
                        } elseif ($hold) {
                            $pairs[$id][2] ??= microtime(true);
                        } else {
                            fwrite($upstream, $data);
                        }
                        break;
                    }
                }
            }
            foreach ($pairs as $id => [$client, $upstream, $heldSince]) {
                if ($heldSince !== null && microtime(true) - $heldSince > 3) {
                    fclose($client);
                    fclose($upstream);
                    unset($pairs[$id]);
                }
            }
        }
        PHP;

    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct()
    {
        $c = postgresConfig();
        $this->process = proc_open(
            [PHP_BINARY, '-n', '-r', self::SCRIPT, '--', $c['host'], (string) $c['port']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $this->pipes,
        );
        $this->port = (int) substr($this->line(), strlen('port '));
    }

    /** Returns once the relay applies the new direction to every byte it reads after this. */
    public function hold(bool $hold): void
    {
        fwrite($this->pipes[0], $hold ? "hold\n" : "pass\n");

        if ($this->line() !== 'ok') {
            throw new RuntimeException('The relay process did not acknowledge the hold change');
        }
    }

    public function close(): void
    {
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private function line(): string
    {
        stream_set_timeout($this->pipes[1], 5);
        $line = fgets($this->pipes[1]);

        if ($line === false) {
            throw new RuntimeException('The relay process did not answer within 5 seconds');
        }

        return trim($line);
    }
}

/** A connector for one Postgres driver, so a test runs the pgsql and the pq handle whichever is preferred. */
function pgDriverConnector(string $class): SqlConnector
{
    return new class($class) implements SqlConnector
    {
        public function __construct(private readonly string $class) {}

        public function connect(SqlConfig $config, ?Cancellation $cancellation = null): PostgresConnection
        {
            return $this->class::connect($config, $cancellation);
        }
    };
}

it('makes a waiter on a full Postgres pool give up with a connection error', function () {
    $pool = new PostgresConnectionPool(pgWaitTimeoutConfig(), 1, waitTimeout: 0.2);

    try {
        [$error, $count, $idle, $awaiting] = waitTimeoutWatchdog(function () use ($pool): array {
            $held = $pool->leaseConnection();
            $waiter = async(fn () => $pool->query('SELECT 2'));

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

    expect($pool->getWaitTimeout())->toBe(0.2)
        ->and($error)->toBeInstanceOf(SqlException::class)
        ->and($error)->not->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getCode())->toBe(2002)
        ->and($error->getMessage())->toBe('Timed out after 0.2s waiting for a connection from the pool (1 of 1 connections in use)')
        ->and($count)->toBe(1)
        ->and($idle)->toBe(0)
        ->and($awaiting)->toBeFalse();
});

it('gives up on a Postgres session reset that outlives the wait timeout and keeps the pool accounting', function (string $class, string $extension) {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("The {$extension} extension is not loaded");
    }

    pgWaitTimeoutConfig();
    $relay = new WaitTimeoutPgRelay;
    $pool = null;

    try {
        $pool = new PostgresConnectionPool(pgWaitTimeoutConfig($relay->port), 1, connector: pgDriverConnector($class), waitTimeout: 0.3);

        [$error, $count, $idle, $awaiting, $first, $next, $elapsed] = waitTimeoutWatchdog(function () use ($pool, $relay): array {
            // A leased connection comes back dirty, so its next checkout runs DISCARD ALL.
            $first = $pool->leaseConnection();
            $pool->releaseConnection($first);

            $relay->hold(true);
            $start = hrtime(true);

            try {
                $pool->leaseConnection();
                $error = null;
            } catch (SqlException $e) {
                $error = $e;
            }

            $snapshot = [$error, $pool->getConnectionCount(), $pool->getIdleConnectionCount(), $pool->isAwaitingConnection()];

            // The slot is free again: the next caller gets a fresh, working connection. The abandoned
            // connection is freed around here, which must not wait for the DISCARD ALL the server never got.
            $relay->hold(false);
            $next = $pool->leaseConnection();
            $next->query('SELECT 1');
            $pool->releaseConnection($next);

            return [...$snapshot, $first, $next, (hrtime(true) - $start) / 1e9];
        });
    } finally {
        try {
            $pool?->close();
        } finally {
            $relay->close();
        }
    }

    // Well below the 3 s after which the relay ends the held session: nothing waited for the server.
    expect($elapsed)->toBeLessThan(2.0)
        ->and($error)->toBeInstanceOf(SqlException::class)
        ->and($error)->not->toBeInstanceOf(SqlConnectionException::class)
        ->and($error->getCode())->toBe(2002)
        ->and($error->getMessage())->toStartWith('Timed out after 0.3s waiting for a connection from the pool')
        ->and($first->isClosed())->toBeTrue()
        ->and($count)->toBe($idle)
        ->and($count)->toBeLessThanOrEqual(1)
        ->and($awaiting)->toBeFalse()
        ->and($next)->not->toBe($first)
        ->and($pool->getConnectionCount())->toBe(1);
})->with([
    'pgsql' => [PgSqlConnection::class, 'pgsql'],
    'pq' => [PqConnection::class, 'pq'],
]);

it('reads pool_wait_timeout on Postgres and surfaces the timeout as a query error Laravel does not retry', function () {
    $config = ['pool_size' => 1, 'pool_wait_timeout' => 0.2] + postgresConfig();
    pgWaitTimeoutConfig();

    $pdo = (new FledgePostgresConnector)->connect($config);
    $connection = new FledgePostgresConnection($pdo, $config['database'], '', $config + ['name' => 'fledge-pgsql-wait', 'driver' => 'fledge-pgsql']);

    try {
        $error = waitTimeoutWatchdog(function () use ($connection, $pdo) {
            // Another caller of the shim's pool holds its only connection.
            $pool = (fn () => $this->pool)->call($pdo);
            $held = $pool->leaseConnection();

            try {
                $connection->select('SELECT 2');

                return null;
            } catch (Throwable $e) {
                return $e;
            } finally {
                $pool->releaseConnection($held);
            }
        });
    } finally {
        $pdo->close();
    }

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getMessage())->toContain('Timed out after 0.2s waiting for a connection from the pool')
        ->and((new LostConnectionDetector)->causedByLostConnection($error))->toBeFalse();
});
