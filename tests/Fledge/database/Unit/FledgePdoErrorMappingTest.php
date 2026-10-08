<?php

use Fledge\Async\CancelledException;
use Fledge\Async\CompositeException;
use Fledge\Async\Database\Postgres\PostgresParseException;
use Fledge\Async\Database\Postgres\PostgresQueryError;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Database\SqlStatement;
use Fledge\Async\Database\SqlTransaction;
use Fledge\Async\Database\SqlTransactionError;
use Fledge\Async\Stream\ClosedException;
use Fledge\Async\Stream\ConnectException;
use Fledge\Async\Stream\TlsException;
use Fledge\Async\TimeoutException;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Fledge\Fiber\Database\Pdo\FledgePdo;
use Fledge\Fiber\Database\Pdo\FledgePdoException;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;

afterEach(fn () => Mockery::close());

function fledgeRetried(Throwable $attempt, string $host = '127.0.0.1:3306'): SqlConnectionException
{
    return new SqlConnectionException(
        "Could not connect to database server at {$host} after 3 tries",
        0,
        new CompositeException([$attempt, $attempt, $attempt]),
    );
}

function fledgeIsUniqueViolation(string $driver, Throwable $e): bool
{
    $connection = $driver === 'pgsql' ? new PostgresConnection(fn () => null) : new MySqlConnection(fn () => null);

    return $e instanceof Exception && (fn () => $this->isUniqueConstraintError($e))->call($connection);
}

const PG_SERVER_CLOSED = "server closed the connection unexpectedly\n\tThis probably means the server terminated abnormally\n\tbefore or while processing the request.";

/*
 * Every throwable the forked drivers raise below the shims, with what pdo_mysql/pdo_pgsql
 * throw in the same situation: [driver, error, code, message, errorInfo, lost, concurrency, unique].
 */
dataset('driver errors', [
    // MySQL / MariaDB: server errors on a statement.
    'mysql 1062 duplicate' => ['mysql', fn () => new SqlQueryError('MySQL error (1062): #23000 Duplicate entry', 'q', null, 1062, '23000', "Duplicate entry 'a' for key 'users_email_unique'"),
        '23000', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a' for key 'users_email_unique'", ['23000', 1062, "Duplicate entry 'a' for key 'users_email_unique'"], false, false, true],
    'mysql 1452 foreign key' => ['mysql', fn () => new SqlQueryError('x', 'q', null, 1452, '23000', 'Cannot add or update a child row: a foreign key constraint fails'),
        '23000', 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails', ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'], false, false, false],
    'mysql 1406 too long' => ['mysql', fn () => new SqlQueryError('x', 'q', null, 1406, '22001', "Data too long for column 'name' at row 1"),
        '22001', "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1", ['22001', 1406, "Data too long for column 'name' at row 1"], false, false, false],
    'mysql 1146 missing table' => ['mysql', fn () => new SqlQueryError('x', 'q', null, 1146, '42S02', "Table 'db.nope' doesn't exist"),
        '42S02', "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.nope' doesn't exist", ['42S02', 1146, "Table 'db.nope' doesn't exist"], false, false, false],
    'mysql 1213 deadlock' => ['mysql', fn () => new SqlQueryError('x', 'q', null, 1213, '40001', 'Deadlock found when trying to get lock; try restarting transaction'),
        '40001', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'], false, true, false],
    'mysql 1205 lock wait timeout' => ['mysql', fn () => new SqlQueryError('x', 'q', null, 1205, 'HY000', 'Lock wait timeout exceeded; try restarting transaction'),
        'HY000', 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'], false, true, false],
    'mysql session init statement' => ['mysql', fn () => new SqlConnectionException('Failed to initialize database session: x', 0, new SqlQueryError('x', 'q', null, 1298, 'HY000', "Unknown or incorrect time zone: 'Mars/Base'")),
        'HY000', "SQLSTATE[HY000]: General error: 1298 Unknown or incorrect time zone: 'Mars/Base'", ['HY000', 1298, "Unknown or incorrect time zone: 'Mars/Base'"], false, false, false],

    // MySQL: connection lost after connecting.
    'mysql lost mid query' => ['mysql', fn () => new SqlConnectionException('Connection closed unexpectedly'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql killed while idle' => ['mysql', fn () => new SqlConnectionException('Connection closed after receiving an unexpected error packet'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql statement on dead connection' => ['mysql', fn () => new SqlConnectionException('Connection went away'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql closed connection error' => ['mysql', fn () => new Error('The connection has been closed'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql closed pool error' => ['mysql', fn () => new Error('The pool has been closed'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql pool closed while waiting' => ['mysql', fn () => new SqlException('Pool closed before an active connection could be obtained'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql statement pool closed' => ['mysql', fn () => new SqlException('The statement has been closed or the connection pool has been closed'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql stream closed' => ['mysql', fn () => new ClosedException('The stream was closed by the peer'),
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql cancelled' => ['mysql', fn () => new CancelledException,
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],
    'mysql timeout' => ['mysql', fn () => new TimeoutException,
        'HY000', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],

    // MySQL: connect failures (pdo_throw_exception shape, int code).
    'mysql 1045 access denied' => ['mysql', fn () => fledgeRetried(new SqlConnectionException("Could not connect to tcp://127.0.0.1:3306: #28000Access denied for user 'x'@'172.17.0.1' (using password: YES)")),
        1045, "SQLSTATE[HY000] [1045] Access denied for user 'x'@'172.17.0.1' (using password: YES)", ['HY000', 1045, "Access denied for user 'x'@'172.17.0.1' (using password: YES)"], true, false, false],
    'mysql 1049 unknown database' => ['mysql', fn () => fledgeRetried(new SqlConnectionException("Could not connect to tcp://127.0.0.1:3306: #42000Unknown database 'nope'")),
        1049, "SQLSTATE[HY000] [1049] Unknown database 'nope'", ['HY000', 1049, "Unknown database 'nope'"], false, false, false],
    'mysql 1040 too many connections' => ['mysql', fn () => fledgeRetried(new SqlConnectionException('Could not connect to tcp://127.0.0.1:3306: Too many connections')),
        1040, 'SQLSTATE[HY000] [1040] Too many connections', ['HY000', 1040, 'Too many connections'], false, false, false],
    'mysql 2002 refused' => ['mysql', fn () => new SqlException('Connecting to the MySQL server failed: Connection to tcp://127.0.0.1:3306 @ tcp://127.0.0.1:3306 refused', 0, new ConnectException('Connection to tcp://127.0.0.1:3306 @ tcp://127.0.0.1:3306 refused', 111)),
        2002, 'SQLSTATE[HY000] [2002] Connection refused', ['HY000', 2002, 'Connection refused'], true, false, false],
    'mysql 2002 timed out' => ['mysql', fn () => new SqlException('Connecting to the MySQL server failed: x', 0, new ConnectException('Connecting to tcp://10.0.0.1:3306 @ tcp://10.0.0.1:3306 failed: timeout exceeded (10.000 s)', 110)),
        2002, 'SQLSTATE[HY000] [2002] Connection timed out', ['HY000', 2002, 'Connection timed out'], true, false, false],
    'mysql 2002 missing socket' => ['mysql', fn () => new SqlException('Connecting to the MySQL server failed: x', 0, new ConnectException('Connection to unix:///tmp/mysql.sock failed: (Error #2) No such file or directory', 2)),
        2002, 'SQLSTATE[HY000] [2002] No such file or directory', ['HY000', 2002, 'No such file or directory'], true, false, false],
    'mysql 2002 dns' => ['mysql', fn () => new SqlException('Connecting to the MySQL server failed: x', 0, new ConnectException('DNS resolution for db.nope failed: no records')),
        2002, 'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db.nope failed: Name or service not known', ['HY000', 2002, 'php_network_getaddresses: getaddrinfo for db.nope failed: Name or service not known'], true, false, false],
    'mysql tls handshake' => ['mysql', fn () => fledgeRetried(new SqlConnectionException('Connection closed unexpectedly', 0, new TlsException('TLS negotiation failed: certificate verify failed'))),
        2002, 'SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL', ['HY000', 2002, 'Cannot connect to MySQL using SSL'], false, false, false],
    'mysql handshake dropped' => ['mysql', fn () => fledgeRetried(new SqlConnectionException('Connection closed unexpectedly')),
        2006, 'SQLSTATE[HY000] [2006] MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], true, false, false],

    // MySQL: protocol and PDO-level errors.
    'mysql protocol error' => ['mysql', fn () => new SqlException('Unexpected string length for datetime in binary protocol: 3'),
        'HY000', 'SQLSTATE[HY000]: General error: 2027 Unexpected string length for datetime in binary protocol: 3', ['HY000', 2027, 'Unexpected string length for datetime in binary protocol: 3'], false, false, false],
    'mysql missing parameter' => ['mysql', fn () => new Error('Parameter 2 missing for executing prepared statement'),
        'HY093', 'SQLSTATE[HY093]: Invalid parameter number: number of bound variables does not match number of tokens', ['HY093', 0], false, false, false],
    'mysql finished transaction' => ['mysql', fn () => new SqlTransactionError('The transaction has been committed or rolled back'),
        0, 'There is no active transaction', null, false, false, false],

    // PostgreSQL: server errors on a statement (native code is PGRES_FATAL_ERROR = 7).
    'pgsql 23505 unique' => ['pgsql', fn () => new PostgresQueryError("ERROR:  duplicate key value violates unique constraint \"u\"\nDETAIL:  Key (email)=(a) already exists.\n", ['sqlstate' => '23505'], 'q'),
        '23505', "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"u\"\nDETAIL:  Key (email)=(a) already exists.", ['23505', 7, "ERROR:  duplicate key value violates unique constraint \"u\"\nDETAIL:  Key (email)=(a) already exists."], false, false, true],
    'pgsql 22P02 bad input' => ['pgsql', fn () => new PostgresQueryError('ERROR:  invalid input syntax for type integer: "x"', ['sqlstate' => '22P02'], 'q'),
        '22P02', 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "x"', ['22P02', 7, 'ERROR:  invalid input syntax for type integer: "x"'], false, false, false],
    'pgsql 40001 serialization' => ['pgsql', fn () => new PostgresQueryError('ERROR:  could not serialize access due to concurrent update', ['sqlstate' => '40001'], 'q'),
        '40001', 'SQLSTATE[40001]: Serialization failure: 7 ERROR:  could not serialize access due to concurrent update', ['40001', 7, 'ERROR:  could not serialize access due to concurrent update'], false, true, false],
    'pgsql 40P01 deadlock' => ['pgsql', fn () => new PostgresQueryError("ERROR:  deadlock detected\nDETAIL:  Process 1 waits for ShareLock", ['sqlstate' => '40P01'], 'q'),
        '40P01', "SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected\nDETAIL:  Process 1 waits for ShareLock", ['40P01', 7, "ERROR:  deadlock detected\nDETAIL:  Process 1 waits for ShareLock"], false, true, false],
    'pgsql 57014 statement timeout' => ['pgsql', fn () => new PostgresQueryError('ERROR:  canceling statement due to statement timeout', ['sqlstate' => '57014'], 'q'),
        '57014', 'SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout', ['57014', 7, 'ERROR:  canceling statement due to statement timeout'], false, false, false],

    'pgsql 57P01 pg_terminate_backend' => ['pgsql', fn () => new PostgresQueryError("FATAL:  terminating connection due to administrator command\n", ['sqlstate' => '57P01'], 'q'),
        'HY000', "SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED, ['HY000', 7, "FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED], true, false, false],
    'pgsql 57P02 crash shutdown' => ['pgsql', fn () => new PostgresQueryError('FATAL:  terminating connection because of crash of another server process', ['sqlstate' => '57P02'], 'q'),
        'HY000', "SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection because of crash of another server process\n".PG_SERVER_CLOSED, ['HY000', 7, "FATAL:  terminating connection because of crash of another server process\n".PG_SERVER_CLOSED], true, false, false],
    'pgsql 57P01 already carrying libpq text' => ['pgsql', fn () => new PostgresQueryError("FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED."\n", ['sqlstate' => '57P01'], 'q'),
        'HY000', "SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED, ['HY000', 7, "FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED], true, false, false],

    // PostgreSQL: connection lost after connecting.
    'pgsql lost mid query' => ['pgsql', fn () => new SqlConnectionException('The connection closed during the operation'),
        'HY000', 'SQLSTATE[HY000]: General error: 7 '.PG_SERVER_CLOSED, ['HY000', 7, PG_SERVER_CLOSED], true, false, false],
    'pgsql dead connection' => ['pgsql', fn () => new Error('The connection to the database has been closed'),
        'HY000', 'SQLSTATE[HY000]: General error: 7 no connection to the server', ['HY000', 7, 'no connection to the server'], true, false, false],
    'pgsql connection closed' => ['pgsql', fn () => new SqlConnectionException('Connection closed'),
        'HY000', 'SQLSTATE[HY000]: General error: 7 no connection to the server', ['HY000', 7, 'no connection to the server'], true, false, false],
    'pgsql statement went away' => ['pgsql', fn () => new SqlException('The statement has been closed or the connection went away'),
        'HY000', 'SQLSTATE[HY000]: General error: 7 no connection to the server', ['HY000', 7, 'no connection to the server'], true, false, false],
    'pgsql FATAL kept by the handle while idle' => ['pgsql', fn () => new SqlConnectionException("FATAL:  terminating connection due to administrator command\n"),
        'HY000', "SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED, ['HY000', 7, "FATAL:  terminating connection due to administrator command\n".PG_SERVER_CLOSED], true, false, false],
    'pgsql cancelled' => ['pgsql', fn () => new CancelledException,
        '57014', 'SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to user request', ['57014', 7, 'ERROR:  canceling statement due to user request'], false, false, false],

    // PostgreSQL: connect failures carry libpq's text under 08006 [7].
    'pgsql refused' => ['pgsql', fn () => fledgeRetried(new SqlConnectionException("connection to server at \"127.0.0.1\", port 15432 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?\n"), '127.0.0.1:15432'),
        7, "SQLSTATE[08006] [7] connection to server at \"127.0.0.1\", port 15432 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?", ['08006', 7, "connection to server at \"127.0.0.1\", port 15432 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?"], true, false, false],
    'pgsql 28P01 bad password' => ['pgsql', fn () => fledgeRetried(new SqlConnectionException("connection to server at \"127.0.0.1\", port 15432 failed: FATAL:  password authentication failed for user \"x\"\n"), '127.0.0.1:15432'),
        7, 'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 15432 failed: FATAL:  password authentication failed for user "x"', ['08006', 7, 'connection to server at "127.0.0.1", port 15432 failed: FATAL:  password authentication failed for user "x"'], false, false, false],
    'pgsql 3D000 unknown database via pecl-pq' => ['pgsql', fn () => fledgeRetried(new SqlConnectionException('Could not connect to PostgreSQL server', 0, new RuntimeException("connection to server at \"127.0.0.1\", port 15432 failed: FATAL:  database \"nope\" does not exist\n")), '127.0.0.1:15432'),
        7, 'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 15432 failed: FATAL:  database "nope" does not exist', ['08006', 7, 'connection to server at "127.0.0.1", port 15432 failed: FATAL:  database "nope" does not exist'], false, false, false],

    // PostgreSQL: protocol and PDO-level errors.
    'pgsql array parse error' => ['pgsql', fn () => new PostgresParseException('Unexpected end of data'),
        'HY000', 'SQLSTATE[HY000]: General error: 7 Parse error while splitting array: Unexpected end of data', ['HY000', 7, 'Parse error while splitting array: Unexpected end of data'], false, false, false],
    'pgsql missing parameter' => ['pgsql', fn () => new Error('Value for unnamed parameter at position 1 missing'),
        'HY093', 'SQLSTATE[HY093]: Invalid parameter number: number of bound variables does not match number of tokens', ['HY093', 0], false, false, false],
    'pgsql finished transaction' => ['pgsql', fn () => new SqlTransactionError('The transaction has already been committed or rolled back'),
        0, 'There is no active transaction', null, false, false, false],
]);

it('maps every driver throwable to the PDOException pdo_mysql/pdo_pgsql throw', function (
    string $driver, Closure $make, int|string $code, string $message, ?array $errorInfo, bool $lost, bool $concurrency, bool $unique,
) {
    $error = $make();
    $e = FledgePdoException::fromThrowable($error, $driver);

    expect($e)->toBeInstanceOf(PDOException::class)
        ->and($e->getCode())->toBe($code)
        ->and($e->getMessage())->toBe($message)
        ->and($e->errorInfo)->toBe($errorInfo)
        ->and($e->getPrevious())->toBe($error)
        ->and((new LostConnectionDetector)->causedByLostConnection($e))->toBe($lost)
        ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($e))->toBe($concurrency)
        ->and(fledgeIsUniqueViolation($driver, $e))->toBe($unique);
})->with('driver errors');

it('returns programming errors untouched', function (Throwable $error) {
    expect(FledgePdoException::fromThrowable($error, 'mysql'))->toBe($error)
        ->and(FledgePdoException::fromThrowable($error, 'pgsql'))->toBe($error);
})->with([
    'TypeError' => [new TypeError('Must provide an instance of MysqlConfig')],
    'ValueError' => [new ValueError('Null not found in string')],
    'ArgumentCountError' => [new ArgumentCountError('Too few arguments')],
    'unknown Error' => [new Error('Host must be provided in connection string')],
]);

it('passes PDOExceptions through', function () {
    $e = new PDOException('already shaped');

    expect(FledgePdoException::fromThrowable($e, 'mysql'))->toBe($e);
});

it('describes unlisted SQLSTATEs like PDO does', function () {
    expect(FledgePdoException::describe('45000'))->toBe('<<Unknown error>>');
});

/*
 * Every public method of the shims must run through guard(), so a future method cannot leak
 * driver exceptions. Only the constructor and the guard plumbing itself are exempt, plus the
 * cross-fiber transaction check, which never calls the driver and must throw its LogicException unmapped.
 */
it('routes every public shim method through guard', function (string $class) {
    $exempt = ['__construct', 'guard', 'toPdoException', 'trackLastInsertId', 'ownsTransaction', 'assertOwnsTransaction'];
    $unguarded = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isAbstract() || in_array($method->getName(), $exempt, true)) {
            continue;
        }

        $lines = file($method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        if (! str_contains($body, '$this->guard(')) {
            $unguarded[] = $method->getDeclaringClass()->getShortName().'::'.$method->getName();
        }
    }

    expect($unguarded)->toBe([]);
})->with([FledgePdo::class, FledgeMySqlPdo::class, FledgePostgresPdo::class, FledgePdoStatement::class]);

it('throws a PDOException from every guarded driver call', function () {
    $lost = new SqlConnectionException('Connection closed unexpectedly');
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('prepare', 'query', 'beginTransaction')->andThrow($lost);

    foreach ([new FledgeMySqlPdo($pool), new FledgePostgresPdo($pool)] as $pdo) {
        expect(fn () => $pdo->prepare('SELECT 1'))->toThrow(PDOException::class)
            ->and(fn () => $pdo->exec('SELECT 1'))->toThrow(PDOException::class)
            ->and(fn () => $pdo->beginTransaction())->toThrow(PDOException::class)
            ->and(fn () => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toThrow(PDOException::class);
    }
});

it('rejects commit and rollBack without a transaction like PDO', function (string $method) {
    $pdo = new FledgeMySqlPdo(Mockery::mock(SqlConnectionPool::class));

    try {
        $pdo->{$method}();
        $this->fail('Expected a PDOException');
    } catch (PDOException $e) {
        expect($e->getMessage())->toBe('There is no active transaction')
            ->and($e->getCode())->toBe(0);
    }
})->with(['commit', 'rollBack']);

it('rejects a nested beginTransaction like PDO and keeps the pinned transaction', function () {
    $transaction = Mockery::mock(SqlTransaction::class);
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('beginTransaction')->once()->andReturn($transaction);

    $pdo = new FledgeMySqlPdo($pool);
    $pdo->beginTransaction();

    expect(fn () => $pdo->beginTransaction())->toThrow(PDOException::class, 'There is already an active transaction')
        ->and($pdo->inTransaction())->toBeTrue();
});

it('releases the transaction when commit fails', function () {
    $transaction = Mockery::mock(SqlTransaction::class);
    $transaction->shouldReceive('commit')->once()->andThrow(
        new SqlQueryError('x', 'COMMIT', null, 1213, '40001', 'Deadlock found when trying to get lock; try restarting transaction'),
    );
    $pool = Mockery::mock(SqlConnectionPool::class);
    $pool->shouldReceive('beginTransaction')->once()->andReturn($transaction);

    $pdo = new FledgeMySqlPdo($pool);
    $pdo->beginTransaction();

    expect(fn () => $pdo->commit())->toThrow(PDOException::class, 'SQLSTATE[40001]')
        ->and($pdo->inTransaction())->toBeFalse();
});

it('shapes errors for the driver given to a statement without a parent PDO', function (string $driver, string $message) {
    $statement = Mockery::mock(SqlStatement::class);
    $statement->shouldReceive('execute')->andThrow(new SqlConnectionException('Connection closed'));

    expect(fn () => (new FledgePdoStatement($statement, driver: $driver))->execute())
        ->toThrow(PDOException::class, $message);
})->with([
    'mysql' => ['mysql', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
    'pgsql' => ['pgsql', 'SQLSTATE[HY000]: General error: 7 no connection to the server'],
]);
