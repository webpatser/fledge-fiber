<?php

use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
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
