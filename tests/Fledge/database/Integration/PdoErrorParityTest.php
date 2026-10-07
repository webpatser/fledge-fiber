<?php

/*
 * Differential error test: every failing statement runs once through real \PDO
 * (pdo_mysql / pdo_pgsql) and once through the Fledge shim, against the same live
 * server, and the resulting errors must be identical. The same scenarios also run
 * through a Laravel Connection on each side, comparing the Illuminate exception.
 *
 * Both Laravel connections resolve their PDO lazily (as ConnectionFactory does), so
 * connect failures surface on the first query on both sides, just as in an app.
 */

use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connections\FledgePostgresConnection;
use Fledge\Fiber\Database\Connectors\FledgeMariaDbConnector;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Fledge\Fiber\Database\Pdo\FledgePdo;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\MariaDbConnector;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;

/**
 * One side of the comparison: a raw PDO (real or shim) or a Laravel Connection.
 */
final class PdoParitySubject
{
    private ?object $pdo = null;

    private function __construct(
        private readonly ?Closure $makePdo,
        private readonly ?Connection $connection,
    ) {}

    public static function raw(Closure $makePdo): self
    {
        return new self($makePdo, null);
    }

    public static function laravel(Connection $connection): self
    {
        return new self(null, $connection);
    }

    private function pdo(): object
    {
        return $this->pdo ??= ($this->makePdo)();
    }

    /**
     * Run a statement the way Connection::statement() does: prepare, bind, execute.
     */
    public function statement(string $sql, array $bindings = []): void
    {
        if ($this->connection !== null) {
            $this->connection->statement($sql, $bindings);

            return;
        }

        $statement = $this->pdo()->prepare($sql);

        foreach (array_values($bindings) as $i => $value) {
            $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->execute();
    }

    /**
     * Run an unprepared statement (PDO::exec / Connection::unprepared).
     */
    public function exec(string $sql): void
    {
        if ($this->connection !== null) {
            $this->connection->unprepared($sql);

            return;
        }

        $this->pdo()->exec($sql);
    }

    public function scalar(string $sql): mixed
    {
        if ($this->connection !== null) {
            return $this->connection->scalar($sql);
        }

        $statement = $this->pdo()->prepare($sql);
        $statement->execute();

        return $statement->fetch(PDO::FETCH_NUM)[0];
    }

    public function begin(): void
    {
        $this->connection !== null ? $this->connection->beginTransaction() : $this->pdo()->beginTransaction();
    }

    public function rollBackQuietly(): void
    {
        try {
            $this->connection !== null ? $this->connection->rollBack() : $this->pdo()->rollBack();
        } catch (Throwable) {
        }
    }

    public function close(): void
    {
        $pdo = $this->pdo;

        if ($this->connection !== null) {
            $property = new ReflectionProperty(Connection::class, 'pdo');
            $pdo = $property->getValue($this->connection);
            $this->connection->disconnect();
        }

        if ($pdo instanceof FledgePdo) {
            try {
                $pdo->close();
            } catch (Throwable) {
            }
        }

        $this->pdo = null;
    }
}

function parityFamily(string $engine): string
{
    return $engine === 'pgsql' ? 'pgsql' : 'mysql';
}

/**
 * @return array<string, mixed>
 */
function parityConfig(string $engine): array
{
    $config = match ($engine) {
        'mysql' => mysqlConfig() + ['driver' => 'mysql', 'strict' => true],
        'mariadb' => mariadbConfig() + ['driver' => 'mariadb', 'strict' => true],
        'pgsql' => postgresConfig() + ['driver' => 'pgsql', 'charset' => 'utf8', 'sslmode' => 'disable'],
    };

    return ['name' => 'parity', 'prefix' => '', 'pool_size' => 2] + $config;
}

function parityAvailable(string $engine): bool
{
    return match ($engine) {
        'mysql' => mysqlAvailable(),
        'mariadb' => mariadbAvailable(),
        'pgsql' => postgresAvailable(),
    };
}

/**
 * A real-PDO superuser connection for setup and for acting on the subject's session.
 */
function parityAdmin(string $engine): PDO
{
    $config = parityConfig($engine);

    if ($engine === 'pgsql') {
        return new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    return new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        'root',
        'root',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/**
 * A second session that can send a query without blocking this process.
 */
function parityAsyncSession(string $engine): mysqli|PgSql\Connection
{
    $config = parityConfig($engine);

    if ($engine === 'pgsql') {
        return pg_connect("host={$config['host']} port={$config['port']} dbname={$config['database']} user={$config['username']} password={$config['password']}", PGSQL_CONNECT_FORCE_NEW);
    }

    mysqli_report(MYSQLI_REPORT_OFF);

    return new mysqli($config['host'], 'root', 'root', $config['database'], $config['port']);
}

function parityAsyncSend(mysqli|PgSql\Connection $session, string $sql): void
{
    if ($session instanceof mysqli) {
        $session->query($sql, MYSQLI_ASYNC);
    } else {
        pg_send_query($session, $sql);
    }
}

function parityAsyncReap(mysqli|PgSql\Connection $session): void
{
    if ($session instanceof mysqli) {
        $session->reap_async_query();
    } else {
        while (pg_get_result($session) !== false) {
        }
    }
}

function parityAsyncRun(mysqli|PgSql\Connection $session, string $sql): void
{
    $session instanceof mysqli ? $session->query($sql) : pg_query($session, $sql);
}

function parityAsyncClose(mysqli|PgSql\Connection $session): void
{
    try {
        $session instanceof mysqli ? $session->close() : pg_close($session);
    } catch (Throwable) {
    }
}

function parityResetTables(string $engine, PDO $admin): void
{
    if ($engine === 'pgsql') {
        $admin->exec('DROP TABLE IF EXISTS _parity_child, _parity_parent, _parity_users, _parity_rows');
        $admin->exec('CREATE TABLE _parity_parent (id INT PRIMARY KEY)');
        $admin->exec('CREATE TABLE _parity_child (id SERIAL PRIMARY KEY, parent_id INT NOT NULL REFERENCES _parity_parent (id))');
        $admin->exec('CREATE TABLE _parity_users (id SERIAL PRIMARY KEY, email VARCHAR(191) NOT NULL UNIQUE, name VARCHAR(5) NULL, age INT NULL)');
        $admin->exec('CREATE TABLE _parity_rows (id INT PRIMARY KEY, n INT NOT NULL)');
    } else {
        $admin->exec('DROP TABLE IF EXISTS _parity_child, _parity_parent, _parity_users, _parity_rows');
        $admin->exec('CREATE TABLE _parity_parent (id INT PRIMARY KEY) ENGINE=InnoDB');
        $admin->exec('CREATE TABLE _parity_child (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT _parity_child_parent_fk FOREIGN KEY (parent_id) REFERENCES _parity_parent (id)) ENGINE=InnoDB');
        $admin->exec('CREATE TABLE _parity_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(191) NOT NULL, name VARCHAR(5) NULL, age INT NULL, UNIQUE KEY _parity_users_email_unique (email)) ENGINE=InnoDB');
        $admin->exec('CREATE TABLE _parity_rows (id INT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB');
        $admin->exec('DROP PROCEDURE IF EXISTS _parity_kill');
        $admin->exec('CREATE PROCEDURE _parity_kill(IN cid BIGINT UNSIGNED) BEGIN DO SLEEP(0.3); KILL cid; END');
    }

    $admin->exec('INSERT INTO _parity_parent (id) VALUES (1)');
    $admin->exec('INSERT INTO _parity_child (parent_id) VALUES (1)');
    $admin->exec("INSERT INTO _parity_users (email) VALUES ('a@example.com')");
    $admin->exec('INSERT INTO _parity_rows (id, n) VALUES (1, 0), (2, 0), (3, 0), (4, 0), (5, 0), (6, 0), (7, 0), (8, 0), (9, 0), (10, 0)');
}

/**
 * The failing scenarios per driver family: [config overrides, run(subject, admin, engine)].
 *
 * @return array<string, array{0: array<string, mixed>, 1: Closure}>
 */
function parityScenarios(string $family): array
{
    $insertUser = 'INSERT INTO _parity_users (email, name, age) VALUES (?, ?, ?)';

    $common = [
        'duplicate key' => [[], fn (PdoParitySubject $s) => $s->statement($insertUser, ['a@example.com', null, null])],
        'foreign key on insert' => [[], fn (PdoParitySubject $s) => $s->statement('INSERT INTO _parity_child (parent_id) VALUES (?)', [999])],
        'foreign key on delete' => [[], fn (PdoParitySubject $s) => $s->statement('DELETE FROM _parity_parent WHERE id = ?', [1])],
        'not null' => [[], fn (PdoParitySubject $s) => $s->statement($insertUser, [null, null, null])],
        'data too long' => [[], fn (PdoParitySubject $s) => $s->statement($insertUser, ['b@example.com', 'toolongname', null])],
        'incorrect value' => [[], fn (PdoParitySubject $s) => $s->statement($insertUser, ['c@example.com', null, 'abc'])],
        'missing table' => [[], fn (PdoParitySubject $s) => $s->statement('SELECT * FROM _parity_nope WHERE id = ?', [1])],
        'unknown column' => [[], fn (PdoParitySubject $s) => $s->statement('SELECT nope FROM _parity_users WHERE id = ?', [1])],
        'syntax error' => [[], fn (PdoParitySubject $s) => $s->statement('SELEC id FROM _parity_users')],
        'unprepared syntax error' => [[], fn (PdoParitySubject $s) => $s->exec('SELEC id FROM _parity_users')],
        'connection refused' => [['port' => 1], fn (PdoParitySubject $s) => $s->scalar('SELECT 1')],
        'bad password' => [['password' => 'wrong'], fn (PdoParitySubject $s) => $s->scalar('SELECT 1')],
    ];

    if ($family === 'pgsql') {
        return $common + [
            'unknown database' => [['database' => 'parity_nope'], fn (PdoParitySubject $s) => $s->scalar('SELECT 1')],
            'tls failure' => [['sslmode' => 'require'], fn (PdoParitySubject $s) => $s->scalar('SELECT 1')],
            'serialization failure' => [[], function (PdoParitySubject $s, PDO $admin) {
                $s->begin();

                try {
                    $s->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    $s->scalar('SELECT n FROM _parity_rows WHERE id = 1');
                    $admin->exec('UPDATE _parity_rows SET n = n + 1 WHERE id = 1');
                    $s->statement('UPDATE _parity_rows SET n = n + 1 WHERE id = ?', [1]);
                } finally {
                    $s->rollBackQuietly();
                }
            }],
            'deadlock' => [[], function (PdoParitySubject $s, PDO $admin, string $engine) {
                $other = parityAsyncSession($engine);
                $s->begin();

                try {
                    $s->exec("SET LOCAL deadlock_timeout = '100ms'");
                    $s->statement('UPDATE _parity_rows SET n = n + 1 WHERE id = ?', [1]);
                    parityAsyncRun($other, "BEGIN; SET LOCAL deadlock_timeout = '10s'; UPDATE _parity_rows SET n = n + 1 WHERE id = 2");
                    parityAsyncSend($other, 'UPDATE _parity_rows SET n = n + 1 WHERE id = 1');
                    usleep(100_000);
                    $s->statement('UPDATE _parity_rows SET n = n + 1 WHERE id = ?', [2]);
                } finally {
                    $s->rollBackQuietly();
                    parityAsyncReap($other);
                    parityAsyncRun($other, 'ROLLBACK');
                    parityAsyncClose($other);
                }
            }],
            'statement timeout' => [[], function (PdoParitySubject $s) {
                $s->begin();

                try {
                    $s->exec("SET LOCAL statement_timeout = '100ms'");
                    $s->scalar('SELECT pg_sleep(2)');
                } finally {
                    $s->rollBackQuietly();
                }
            }],
            'terminated while idle' => [[], function (PdoParitySubject $s, PDO $admin) {
                $s->begin();

                try {
                    $pid = (int) $s->scalar('SELECT pg_backend_pid()');
                    $admin->exec("SELECT pg_terminate_backend({$pid})");
                    usleep(200_000);
                    $s->scalar('SELECT 1');
                } finally {
                    $s->rollBackQuietly();
                }
            }],
            'terminated mid-query' => [[], function (PdoParitySubject $s, PDO $admin, string $engine) {
                $other = parityAsyncSession($engine);
                $s->begin();

                try {
                    $pid = (int) $s->scalar('SELECT pg_backend_pid()');
                    parityAsyncSend($other, "SELECT pg_sleep(0.3), pg_terminate_backend({$pid})");
                    $s->scalar('SELECT pg_sleep(3)');
                } finally {
                    $s->rollBackQuietly();
                    parityAsyncReap($other);
                    parityAsyncClose($other);
                }
            }],
        ];
    }

    return $common + [
        'unknown database' => [['database' => 'parity_nope', 'username' => 'root', 'password' => 'root'], fn (PdoParitySubject $s) => $s->scalar('SELECT 1')],
        'tls failure' => [['options' => [Pdo\Mysql::ATTR_SSL_CA => parityForeignCa(), Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true]], fn (PdoParitySubject $s) => $s->scalar('SELECT 1')],
        'lock wait timeout' => [[], function (PdoParitySubject $s, PDO $admin) {
            $admin->beginTransaction();
            $admin->query('SELECT id FROM _parity_rows WHERE id = 1 FOR UPDATE')->fetchAll();
            $s->begin();

            try {
                $s->exec('SET SESSION innodb_lock_wait_timeout = 1');
                $s->statement('UPDATE _parity_rows SET n = n + 1 WHERE id = ?', [1]);
            } finally {
                $s->rollBackQuietly();
                $admin->rollBack();
            }
        }],
        'deadlock' => [[], function (PdoParitySubject $s, PDO $admin, string $engine) {
            $other = parityAsyncSession($engine);
            $s->begin();

            try {
                $s->statement('UPDATE _parity_rows SET n = n + 1 WHERE id = ?', [1]);
                // The other session changes more rows, so InnoDB picks the subject as the victim.
                parityAsyncRun($other, 'START TRANSACTION');
                parityAsyncRun($other, 'UPDATE _parity_rows SET n = n + 1 WHERE id BETWEEN 2 AND 10');
                parityAsyncSend($other, 'UPDATE _parity_rows SET n = n + 1 WHERE id = 1');
                usleep(100_000);
                $s->statement('UPDATE _parity_rows SET n = n + 1 WHERE id = ?', [2]);
            } finally {
                $s->rollBackQuietly();
                parityAsyncReap($other);
                parityAsyncRun($other, 'ROLLBACK');
                parityAsyncClose($other);
            }
        }],
        'killed while idle' => [[], function (PdoParitySubject $s, PDO $admin) {
            $s->begin();

            try {
                $id = (int) $s->scalar('SELECT CONNECTION_ID()');
                $admin->exec("KILL {$id}");
                usleep(200_000);
                $s->scalar('SELECT 1');
            } finally {
                $s->rollBackQuietly();
            }
        }],
        'killed mid-query' => [[], function (PdoParitySubject $s, PDO $admin, string $engine) {
            $other = parityAsyncSession($engine);
            $s->begin();

            try {
                $id = (int) $s->scalar('SELECT CONNECTION_ID()');
                parityAsyncSend($other, "CALL _parity_kill({$id})");
                $s->scalar('SELECT SLEEP(3)');
            } finally {
                $s->rollBackQuietly();
                parityAsyncReap($other);
                parityAsyncClose($other);
            }
        }],
    ];
}

/**
 * A CA bundle that did not sign the server certificate, so verification fails.
 */
function parityForeignCa(): string
{
    foreach (['/etc/ssl/cert.pem', '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return openssl_get_cert_locations()['default_cert_file'];
}

/**
 * @return array<string, array{0: string, 1: string}>
 */
function parityDataset(): array
{
    $dataset = [];

    foreach (['mysql', 'mariadb', 'pgsql'] as $engine) {
        foreach (array_keys(parityScenarios(parityFamily($engine))) as $case) {
            $dataset["{$engine}: {$case}"] = [$engine, $case];
        }
    }

    return $dataset;
}

/**
 * Remove the parts of a server message that differ between two identical runs.
 */
function parityNormalize(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(parityNormalize(...), $value);
    }

    if (! is_string($value)) {
        return $value;
    }

    return preg_replace(
        ['/\b([Pp]rocess) \d+/', '/\btransaction \d+/', '/\btuple \(\d+,\d+\)/'],
        ['$1 N', 'transaction N', 'tuple (N,N)'],
        $value,
    );
}

/**
 * The comparable shape of the error a scenario raised (or the lack of one).
 *
 * @return array<string, mixed>
 */
function parityCapture(string $engine, string $case, PdoParitySubject $subject, PDO $admin): array
{
    [, $run] = parityScenarios(parityFamily($engine))[$case];

    // Real drivers emit PHP warnings alongside some connect failures; only the exception is compared.
    set_error_handler(fn () => true, E_WARNING | E_NOTICE);

    try {
        $run($subject, $admin, $engine);

        return ['class' => null, 'note' => 'no exception thrown'];
    } catch (Throwable $e) {
        $previous = $e->getPrevious();

        return [
            'class' => $e instanceof PDOException ? PDOException::class : $e::class,
            'code' => $e->getCode(),
            'errorInfo' => parityNormalize(property_exists($e, 'errorInfo') ? $e->errorInfo : null),
            'message' => parityNormalize($e->getMessage()),
            'previous' => match (true) {
                $previous === null => null,
                $previous instanceof PDOException => PDOException::class,
                default => $previous::class,
            },
        ];
    } finally {
        restore_error_handler();
        $subject->close();
    }
}

/**
 * @return array{0: Closure(array): object, 1: Closure(array): object}
 */
function parityPdoFactories(string $engine): array
{
    return match ($engine) {
        'mysql' => [fn (array $c) => (new MySqlConnector)->connect($c), fn (array $c) => (new FledgeMySqlConnector)->connect($c)],
        'mariadb' => [fn (array $c) => (new MariaDbConnector)->connect($c), fn (array $c) => (new FledgeMariaDbConnector)->connect($c)],
        'pgsql' => [fn (array $c) => (new PostgresConnector)->connect($c), fn (array $c) => (new FledgePostgresConnector)->connect($c)],
    };
}

function parityLaravelConnection(string $engine, bool $fledge, array $config): Connection
{
    [$real, $shim] = parityPdoFactories($engine);
    $resolver = $fledge ? fn () => $shim($config) : fn () => $real($config);

    $class = match ($engine) {
        'mysql' => $fledge ? FledgeMySqlConnection::class : MySqlConnection::class,
        'mariadb' => $fledge ? FledgeMariaDbConnection::class : MariaDbConnection::class,
        'pgsql' => $fledge ? FledgePostgresConnection::class : PostgresConnection::class,
    };

    return new $class($resolver, $config['database'], '', $config);
}

/**
 * Run the scenario against real PDO and against Fledge on fresh tables, return both shapes.
 *
 * @return array{real: array<string, mixed>, fledge: array<string, mixed>}
 */
function parityCompare(string $engine, string $case, bool $laravel): array
{
    [$overrides] = parityScenarios(parityFamily($engine))[$case];
    $config = array_replace(parityConfig($engine), $overrides);
    [$real, $shim] = parityPdoFactories($engine);
    $admin = parityAdmin($engine);

    // Serialise concurrent runs of this file: they share the _parity_* tables.
    $admin->query($engine === 'pgsql' ? 'SELECT pg_advisory_lock(727274)' : "SELECT GET_LOCK('fledge_pdo_parity', 300)")->fetchAll();

    $results = [];

    try {
        foreach (['real' => false, 'fledge' => true] as $side => $fledge) {
            parityResetTables($engine, $admin);

            $subject = $laravel
                ? PdoParitySubject::laravel(parityLaravelConnection($engine, $fledge, $config))
                : PdoParitySubject::raw(fn () => ($fledge ? $shim : $real)($config));

            $results[$side] = parityCapture($engine, $case, $subject, $admin);
        }
    } finally {
        if ($admin->inTransaction()) {
            $admin->rollBack();
        }

        $admin->query($engine === 'pgsql' ? 'SELECT pg_advisory_unlock(727274)' : "SELECT RELEASE_LOCK('fledge_pdo_parity')")->fetchAll();
    }

    return $results;
}

/**
 * Both shapes, printed in full so a failure shows the exact real and Fledge values.
 */
function parityMismatch(array $real, array $fledge): string
{
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    return 'PARITY real='.json_encode($real, $flags).' fledge='.json_encode($fledge, $flags);
}

beforeEach(function () {
    foreach (['mysql', 'mariadb', 'pgsql'] as $engine) {
        if (! parityAvailable($engine)) {
            $this->markTestSkipped("{$engine} test server is not running (docker compose up -d mysql mariadb postgres)");
        }
    }
});

it('raises the same PDOException through the Fledge shim as through real PDO', function (string $engine, string $case) {
    ['real' => $real, 'fledge' => $fledge] = parityCompare($engine, $case, laravel: false);

    // Real PDO raises its own exception; the shim keeps the driver exception as previous (house rule).
    unset($real['previous'], $fledge['previous']);

    expect($real['class'])->toBe(PDOException::class, "real PDO did not fail on {$engine}: {$case}")
        ->and($fledge)->toBe($real, parityMismatch($real, $fledge));
})->with(parityDataset());

it('raises the same Laravel exception through a Fledge connection as through a PDO connection', function (string $engine, string $case) {
    ['real' => $real, 'fledge' => $fledge] = parityCompare($engine, $case, laravel: true);

    expect($real['class'])->not->toBeNull("Laravel on real PDO did not fail on {$engine}: {$case}")
        ->and($fledge)->toBe($real, parityMismatch($real, $fledge));
})->with(parityDataset());
