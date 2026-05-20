<?php

use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;

uses()->beforeEach(function () {
    if (! mariadbAvailable()) {
        $this->markTestSkipped('MariaDB not available on port '.test_env('FLEDGE_TEST_MARIADB_PORT', 13307));
    }
});

it('round-trips a value through a native UUID column', function () {
    $pdo = mariadbConnection();

    if (! $pdo instanceof FledgeMySqlPdo) {
        $this->markTestSkipped('MariaDB connection could not be established');
    }

    $uuid = '6f9619ff-8b86-d011-b42d-00cf4fc964ff';

    $pdo->exec('DROP TABLE IF EXISTS _fledge_mariadb_uuid');
    $pdo->exec('CREATE TABLE _fledge_mariadb_uuid (id INT AUTO_INCREMENT PRIMARY KEY, ref UUID)');

    $insert = $pdo->prepare('INSERT INTO _fledge_mariadb_uuid (ref) VALUES (?)');
    $insert->bindValue(1, $uuid);
    $insert->execute();

    $select = $pdo->prepare('SELECT ref FROM _fledge_mariadb_uuid WHERE ref = ?');
    $select->bindValue(1, $uuid);
    $select->execute();
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['ref'])->toBe($uuid);

    $pdo->exec('DROP TABLE IF EXISTS _fledge_mariadb_uuid');
    $pdo->close();
});
