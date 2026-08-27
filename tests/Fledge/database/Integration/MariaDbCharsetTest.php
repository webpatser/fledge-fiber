<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;

uses()->beforeEach(function () {
    if (! mariadbAvailable()) {
        $this->markTestSkipped('MariaDB not available on port '.test_env('FLEDGE_TEST_MARIADB_PORT', 13307));
    }
});

it('connects with utf8mb4 and an explicit collation', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'] + mariadbConfig());

    $stmt = $pdo->prepare('SELECT @@session.character_set_client AS cs, @@session.collation_connection AS col');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows[0]['cs'])->toBe('utf8mb4')
        ->and($rows[0]['col'])->toBe('utf8mb4_unicode_ci');

    $pdo->close();
});

it('connects with utf8mb4 without a collation', function () {
    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(['charset' => 'utf8mb4'] + mariadbConfig());

    $stmt = $pdo->prepare('SELECT @@session.character_set_client AS cs');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows[0]['cs'])->toBe('utf8mb4');

    $pdo->close();
});
