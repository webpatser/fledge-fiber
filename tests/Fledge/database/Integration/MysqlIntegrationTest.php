<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;

function mysqlConfig(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_MYSQL_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_MYSQL_PORT', 13306),
        'username' => test_env('FLEDGE_TEST_MYSQL_USER', 'fledge'),
        'password' => test_env('FLEDGE_TEST_MYSQL_PASSWORD', 'fledge'),
        'database' => test_env('FLEDGE_TEST_MYSQL_DATABASE', 'fledge_test'),
        'charset' => 'utf8mb4',
    ];
}

function mysqlAvailable(): bool
{
    $host = test_env('FLEDGE_TEST_MYSQL_HOST', '127.0.0.1');
    $port = (int) test_env('FLEDGE_TEST_MYSQL_PORT', 13306);
    $sock = @fsockopen($host, $port, $errno, $errstr, 1);
    if (! $sock) {
        return false;
    }
    fclose($sock);

    return true;
}

uses()->beforeEach(function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available on port '.test_env('FLEDGE_TEST_MYSQL_PORT', 13306));
    }
});

it('connects and executes a query', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    expect($pdo)->toBeInstanceOf(FledgeMySqlPdo::class);

    $result = $pdo->exec('SELECT 1');
    expect($result)->toBeInt();

    $pdo->close();
});

it('inserts a row and verifies lastInsertId', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_insert');
    $pdo->exec('CREATE TABLE _fledge_test_insert (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');
    $pdo->exec("INSERT INTO _fledge_test_insert (name) VALUES ('hello')");

    $lastId = $pdo->lastInsertId();
    expect($lastId)->not->toBeFalse();
    expect((int) $lastId)->toBeGreaterThan(0);

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_insert');
    $pdo->close();
});

it('selects rows using a prepared statement', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_select');
    $pdo->exec('CREATE TABLE _fledge_test_select (id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(255))');
    $pdo->exec("INSERT INTO _fledge_test_select (val) VALUES ('alpha'), ('beta'), ('gamma')");

    $stmt = $pdo->prepare('SELECT val FROM _fledge_test_select WHERE val = ?');
    $stmt->bindValue(1, 'beta');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['val'])->toBe('beta');

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_select');
    $pdo->close();
});

it('handles prepared statements with multiple parameters', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_params');
    $pdo->exec('CREATE TABLE _fledge_test_params (id INT AUTO_INCREMENT PRIMARY KEY, a VARCHAR(50), b INT)');
    $pdo->exec("INSERT INTO _fledge_test_params (a, b) VALUES ('x', 1), ('y', 2), ('z', 3)");

    $stmt = $pdo->prepare('SELECT a, b FROM _fledge_test_params WHERE a = ? AND b = ?');
    $stmt->bindValue(1, 'y');
    $stmt->bindValue(2, 2, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['a'])->toBe('y');

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_params');
    $pdo->close();
});

it('commits a transaction', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_txn');
    $pdo->exec('CREATE TABLE _fledge_test_txn (id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(50))');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO _fledge_test_txn (val) VALUES ('committed')");
    $pdo->commit();

    $stmt = $pdo->prepare('SELECT val FROM _fledge_test_txn');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['val'])->toBe('committed');

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_txn');
    $pdo->close();
});

it('rolls back a transaction', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_rollback');
    $pdo->exec('CREATE TABLE _fledge_test_rollback (id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(50))');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO _fledge_test_rollback (val) VALUES ('rolled_back')");
    $pdo->rollBack();

    $stmt = $pdo->prepare('SELECT val FROM _fledge_test_rollback');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(0);

    $pdo->exec('DROP TABLE IF EXISTS _fledge_test_rollback');
    $pdo->close();
});

it('closes without error', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mysqlConfig());

    $pdo->exec('SELECT 1');
    $pdo->close();

    expect(true)->toBeTrue();
});
