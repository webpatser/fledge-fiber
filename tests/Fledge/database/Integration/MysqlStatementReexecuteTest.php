<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnection;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Stream\DnsSocketConnector;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;
use Fledge\Fiber\Database\Pdo\FledgePdo;
use Revolt\EventLoop;

/**
 * Re-executing one prepared statement against real MySQL and MariaDB, with the outcomes of real
 * pdo_mysql with emulation off. The exception is a repeated named parameter, where pdo_mysql throws
 * HY093 and this driver returns the value in every position.
 */
const REEXECUTE_U1 = '11111111-1111-4111-8111-111111111111';

const REEXECUTE_U2 = '22222222-2222-4222-8222-222222222222';

function reexecuteConfig(string $flavor): array
{
    return $flavor === 'mariadb' ? mariadbConfig() : mysqlConfig();
}

function reexecuteSkipUnless(string $flavor, object $test): void
{
    $available = $flavor === 'mariadb' ? mariadbAvailable() : mysqlAvailable();

    if (! $available) {
        $test->markTestSkipped(ucfirst($flavor).' not available');
    }
}

/**
 * Run a scenario through a real FledgePdo, in autocommit or in a transaction.
 *
 * @param  Closure(FledgePdo):void  $scenario
 */
function reexecuteOnPdo(string $flavor, string $mode, Closure $scenario, int $poolSize = 5): void
{
    $pdo = (new FledgeMySqlConnector)->connect(['pool_size' => $poolSize] + reexecuteConfig($flavor));

    try {
        if ($mode === 'transaction') {
            $pdo->beginTransaction();
        }

        $scenario($pdo);

        if ($mode === 'transaction') {
            $pdo->rollBack();
        }
    } finally {
        $pdo->close();
    }
}

/**
 * The rows of the last execute, as plain lists.
 */
function reexecuteRows(object $statement): array
{
    return array_map(array_values(...), $statement->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * @param  Closure(SocketMysqlConnection):void  $scenario
 */
function reexecuteOnRawConnection(string $flavor, Closure $scenario): void
{
    $config = reexecuteConfig($flavor);
    $connection = SocketMysqlConnection::connect(new DnsSocketConnector, new MysqlConfig(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password'],
        $config['database'],
    ));

    try {
        $scenario($connection);
    } finally {
        $connection->close();
    }
}

function reexecuteRawRow(object $result): array
{
    $row = $result->fetchRow();

    while ($result->fetchRow() !== null) {
        // Drain, so the connection can take the next command.
    }

    return array_values($row);
}

foreach (['mysql', 'mariadb'] as $flavor) {
    foreach (['autocommit', 'transaction'] as $mode) {
        $where = "on {$flavor} in {$mode}";

        it("returns the second uuid when execute gets a new one {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) {
                $statement = $pdo->prepare('SELECT ? AS a');

                foreach ([REEXECUTE_U1, REEXECUTE_U2, REEXECUTE_U1] as $uuid) {
                    $statement->execute([$uuid]);
                    expect(reexecuteRows($statement))->toBe([[$uuid]]);
                }
            });
        });

        it("returns a plain value after a uuid, and a uuid after a plain value {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) {
                $statement = $pdo->prepare('SELECT ? AS a');

                foreach ([REEXECUTE_U1, 'plain', REEXECUTE_U2] as $value) {
                    $statement->execute([$value]);
                    expect(reexecuteRows($statement))->toBe([[$value]]);
                }
            });
        });

        it("reuses the value on an execute without params {$where}", function (string $value, string $how) use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) use ($value, $how) {
                $statement = $pdo->prepare('SELECT ? AS a');

                if ($how === 'bindValue') {
                    $statement->bindValue(1, $value);
                    $statement->execute();
                } else {
                    $statement->execute([$value]);
                }

                expect(reexecuteRows($statement))->toBe([[$value]]);

                foreach ([1, 2] as $again) {
                    $statement->execute();
                    expect(reexecuteRows($statement))->toBe([[$value]]);
                }
            });
        })->with([
            'bound uuid' => [REEXECUTE_U1, 'bindValue'],
            'uuid arg' => [REEXECUTE_U1, 'execute'],
            'bound plain' => ['plain', 'bindValue'],
            'plain arg' => ['plain', 'execute'],
        ]);

        it("returns the new value after a rebind, without concatenating {$where}", function (string $first, string $second, string $how) use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) use ($first, $second, $how) {
                $statement = $pdo->prepare('SELECT ? AS a');

                if ($how === 'bindValue') {
                    $statement->bindValue(1, $first);
                    $statement->execute();
                } else {
                    $statement->execute([$first]);
                }

                expect(reexecuteRows($statement))->toBe([[$first]]);

                $statement->bindValue(1, $second);
                $statement->execute();

                expect(reexecuteRows($statement))->toBe([[$second]]);
            });
        })->with([
            'uuid over bound uuid' => [REEXECUTE_U1, REEXECUTE_U2, 'bindValue'],
            'uuid over uuid arg' => [REEXECUTE_U1, REEXECUTE_U2, 'execute'],
            'plain over bound uuid' => [REEXECUTE_U1, 'plain', 'bindValue'],
            'uuid over bound plain' => ['plain', REEXECUTE_U1, 'bindValue'],
        ]);

        it("collapses bound keys in key order {$where}", function (array $bindings, array $expected) use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) use ($bindings, $expected) {
                $placeholders = array_map(fn (int $i) => "? AS c{$i}", array_keys($expected));
                $statement = $pdo->prepare('SELECT '.implode(', ', $placeholders));

                foreach ($bindings as [$key, $value]) {
                    $statement->bindValue($key, $value);
                }

                $statement->execute();
                expect(reexecuteRows($statement))->toBe([$expected]);

                $statement->execute();
                expect(reexecuteRows($statement))->toBe([$expected]);
            });
        })->with([
            'a gap' => [[[2, 'x']], ['x']],
            'a gap with a uuid' => [[[2, REEXECUTE_U1]], [REEXECUTE_U1]],
            'two gaps' => [[[2, 'x'], [5, REEXECUTE_U2]], ['x', REEXECUTE_U2]],
            '0-based' => [[[0, 'x']], ['x']],
            '0-based, two values' => [[[0, REEXECUTE_U1], [1, 'y']], [REEXECUTE_U1, 'y']],
            'out of order' => [[[2, 'B'], [1, 'A']], ['A', 'B']],
            'out of order with uuids' => [[[3, REEXECUTE_U2], [1, REEXECUTE_U1], [2, 'p']], [REEXECUTE_U1, 'p', REEXECUTE_U2]],
        ]);

        it("re-executes while the previous result has unread rows {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) {
                $watchdog = EventLoop::unreference(EventLoop::delay(5, static fn () => $pdo->close()));

                try {
                    $statement = $pdo->prepare('SELECT ? AS a FROM (SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) t');

                    $statement->execute([REEXECUTE_U1]);
                    expect($statement->fetch(PDO::FETCH_NUM))->toBe([REEXECUTE_U1]);

                    $statement->execute([REEXECUTE_U2]);
                    expect($statement->fetchAll(PDO::FETCH_NUM))->toBe(array_fill(0, 4, [REEXECUTE_U2]));
                } finally {
                    EventLoop::cancel($watchdog);
                }
            });
        });

        it("starts clean after a prebind failed halfway through the params {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) {
                $statement = $pdo->prepare('SELECT ? AS a');

                expect(fn () => $statement->execute([REEXECUTE_U1, REEXECUTE_U2]))->toThrow(PDOException::class, 'HY093');

                $statement->execute([REEXECUTE_U2]);

                expect(reexecuteRows($statement))->toBe([[REEXECUTE_U2]]);
            });
        });

        it("keeps every column in place across re-executes with mixed values {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) {
                $statement = $pdo->prepare('SELECT ? AS a, ? AS b, ? AS c');

                foreach ([[REEXECUTE_U1, 'one', REEXECUTE_U2], ['two', REEXECUTE_U2, REEXECUTE_U1], [REEXECUTE_U2, 'three', 'four']] as $values) {
                    $statement->execute($values);
                    expect(reexecuteRows($statement))->toBe([$values]);
                }
            });
        });

        it("returns a repeated named uuid in every column {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) {
                $statement = $pdo->prepare('SELECT :a AS x, :a AS y');

                foreach ([REEXECUTE_U1, REEXECUTE_U2, 'plain'] as $value) {
                    $statement->execute(['a' => $value]);
                    expect(reexecuteRows($statement))->toBe([[$value, $value]]);
                }

                $statement->bindValue('a', REEXECUTE_U1);
                $statement->execute();
                expect(reexecuteRows($statement))->toBe([[REEXECUTE_U1, REEXECUTE_U1]]);

                $statement->execute();
                expect(reexecuteRows($statement))->toBe([[REEXECUTE_U1, REEXECUTE_U1]]);
            });
        });

        it("stores the right values when an insert is re-executed {$where}", function () use ($flavor, $mode) {
            reexecuteSkipUnless($flavor, $this);

            // One connection, so the temporary table is there for each statement in autocommit too.
            reexecuteOnPdo($flavor, $mode, function (FledgePdo $pdo) use ($flavor) {
                $column = $flavor === 'mariadb' ? 'UUID' : 'CHAR(36)';
                $pdo->exec("CREATE TEMPORARY TABLE _fledge_reexecute (id INT AUTO_INCREMENT PRIMARY KEY, ref {$column} NULL, note VARCHAR(20) NULL)");

                $insert = $pdo->prepare('INSERT INTO _fledge_reexecute (ref, note) VALUES (?, ?)');
                $insert->execute([REEXECUTE_U1, 'first']);
                $insert->execute([REEXECUTE_U2, 'second']);
                $insert->execute();
                $insert->bindValue(1, REEXECUTE_U1);
                $insert->execute();
                $insert->execute([null, 'none']);

                expect($insert->rowCount())->toBe(1);

                // An open result holds the only connection of this pool, as it does after one execute.
                $insert->closeCursor();

                $select = $pdo->prepare('SELECT ref, note FROM _fledge_reexecute ORDER BY id');
                $select->execute();

                expect(reexecuteRows($select))->toBe([
                    [REEXECUTE_U1, 'first'],
                    [REEXECUTE_U2, 'second'],
                    [REEXECUTE_U2, 'second'],
                    [REEXECUTE_U1, 'second'],
                    [null, 'none'],
                ]);

                $pdo->exec('DROP TEMPORARY TABLE _fledge_reexecute');
            }, poolSize: 1);
        });
    }

    it("runs a Laravel select whose bindings have a gap on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnPdo($flavor, 'autocommit', function (FledgePdo $pdo) {
            $connection = new FledgeMariaDbConnection($pdo, 'fledge_test', '', ['driver' => 'mariadb', 'name' => 'reexecute']);

            $rows = $connection->select('SELECT ? AS a, ? AS b', array_filter(['', 'x', 0, REEXECUTE_U1]));

            expect(array_map(fn (object $row) => (array) $row, $rows))->toBe([['a' => 'x', 'b' => REEXECUTE_U1]]);
        });
    });

    it("re-executes on a pool of one connection on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnPdo($flavor, 'autocommit', function (FledgePdo $pdo) {
            $statement = $pdo->prepare('SELECT ? AS a');

            foreach ([REEXECUTE_U1, REEXECUTE_U2, 'plain', REEXECUTE_U1] as $value) {
                $statement->execute([$value]);
                expect(reexecuteRows($statement))->toBe([[$value]]);
            }

            $statement->execute();
            expect(reexecuteRows($statement))->toBe([[REEXECUTE_U1]]);
        }, poolSize: 1);
    });

    it("recovers from an execute the server rejected on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnPdo($flavor, 'autocommit', function (FledgePdo $pdo) {
            $statement = $pdo->prepare("SELECT ? AS a, (SELECT 1 FROM (SELECT 1 UNION SELECT 2) t WHERE ? = 'boom') AS b");

            // This server error comes with the rows, not with the execute.
            expect(function () use ($statement) {
                $statement->execute([REEXECUTE_U1, 'boom']);
                reexecuteRows($statement);
            })->toThrow(PDOException::class, '1242');

            $statement->execute([REEXECUTE_U2, 'fine']);
            expect(reexecuteRows($statement))->toBe([[REEXECUTE_U2, null]]);
        });
    });

    it("throws on a raw re-execute without rebinding on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnRawConnection($flavor, function (SocketMysqlConnection $connection) {
            $statement = $connection->prepare('SELECT ? AS a');
            $statement->bind(0, 'A');

            expect(reexecuteRawRow($statement->execute()))->toBe(['A'])
                ->and(fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing');

            $statement->bind(0, 'B');

            expect(reexecuteRawRow($statement->execute()))->toBe(['B']);
        });
    });

    it("does not return NULL for a raw mix of bind and args on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnRawConnection($flavor, function (SocketMysqlConnection $connection) {
            $statement = $connection->prepare('SELECT ? AS a, ? AS b');
            $statement->bind(0, 'B0');

            expect(reexecuteRawRow($statement->execute([1 => 'a1'])))->toBe(['B0', 'a1'])
                ->and(fn () => $statement->execute([1 => 'a1']))->toThrow(Error::class, 'Parameter 0 missing');

            expect(reexecuteRawRow($statement->execute(['x', 'y'])))->toBe(['x', 'y']);
        });
    });

    it("keeps later args in place when a bound parameter also has an arg on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnRawConnection($flavor, function (SocketMysqlConnection $connection) {
            $statement = $connection->prepare('SELECT ? AS a, ? AS b, ? AS c');
            $statement->bind(1, 'BOUND');

            expect(reexecuteRawRow($statement->execute(['x', 'y', 'z'])))->toBe(['x', 'BOUND', 'z']);
        });
    });

    it("returns repeated and mixed named parameters from a raw statement on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnRawConnection($flavor, function (SocketMysqlConnection $connection) {
            $statement = $connection->prepare('SELECT ? AS p0, :a AS a1, ? AS p1, :b AS b1, :a AS a2');
            $statement->bind(0, 'p0');
            $statement->bind('a', 'na');
            $statement->bind(1, 'p1');
            $statement->bind('b', 'nb');

            expect(reexecuteRawRow($statement->execute()))->toBe(['p0', 'na', 'p1', 'nb', 'na'])
                ->and(fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing');
        });
    });

    it("leaves no stale data after a raw execute fails on {$flavor}", function (string $failure) use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnRawConnection($flavor, function (SocketMysqlConnection $connection) use ($failure) {
            $statement = $connection->prepare("SELECT ? AS a, (SELECT 1 FROM (SELECT 1 UNION SELECT 2) t WHERE ? = 'boom') AS b");
            $statement->bind(0, 'LONG');

            if ($failure === 'server') {
                // This server error comes with the rows, not with the execute.
                expect(fn () => reexecuteRawRow($statement->execute([1 => 'boom'])))->toThrow(SqlQueryError::class, '1242');
            } else {
                expect(fn () => $statement->execute([1 => new stdClass]))->toThrow(TypeError::class, 'stdClass');
            }

            expect(reexecuteRawRow($statement->execute(['y', 'fine'])))->toBe(['y', null]);
        });
    })->with(['server', 'encoding']);

    it("leaves no stale data after the server rejects a raw execute outright on {$flavor}", function () use ($flavor) {
        reexecuteSkipUnless($flavor, $this);

        reexecuteOnRawConnection($flavor, function (SocketMysqlConnection $connection) {
            $connection->query('CREATE TEMPORARY TABLE _fledge_reexecute_raw (k VARCHAR(10) PRIMARY KEY, v VARCHAR(10))');
            $connection->query("INSERT INTO _fledge_reexecute_raw VALUES ('taken', 'old')");

            $statement = $connection->prepare('INSERT INTO _fledge_reexecute_raw VALUES (?, ?)');
            $statement->bind(1, 'LONG');

            expect(fn () => $statement->execute(['taken']))->toThrow(SqlQueryError::class, '1062');

            $statement->execute(['free', 'fresh']);

            expect(reexecuteRawRow($connection->query("SELECT v FROM _fledge_reexecute_raw WHERE k = 'free'")))->toBe(['fresh']);
        });
    });
}
