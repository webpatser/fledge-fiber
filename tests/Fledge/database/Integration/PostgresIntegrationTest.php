<?php

use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;

function postgresConfig(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_POSTGRES_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_POSTGRES_PORT', 15432),
        'username' => test_env('FLEDGE_TEST_POSTGRES_USER', 'fledge'),
        'password' => test_env('FLEDGE_TEST_POSTGRES_PASSWORD', 'fledge'),
        'database' => test_env('FLEDGE_TEST_POSTGRES_DATABASE', 'fledge_test'),
    ];
}

function postgresAvailable(): bool
{
    $host = test_env('FLEDGE_TEST_POSTGRES_HOST', '127.0.0.1');
    $port = (int) test_env('FLEDGE_TEST_POSTGRES_PORT', 15432);
    $sock = @fsockopen($host, $port, $errno, $errstr, 1);
    if (! $sock) {
        return false;
    }
    fclose($sock);

    return true;
}

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
