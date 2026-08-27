<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;

uses()->beforeEach(function () {
    if (! mariadbAvailable()) {
        $this->markTestSkipped('MariaDB not available on port '.test_env('FLEDGE_TEST_MARIADB_PORT', 13307));
    }
});

it('negotiates tls when ssl options are configured', function () {
    $plain = mariadbConnection();

    if (! $plain instanceof FledgeMySqlPdo) {
        $this->markTestSkipped('MariaDB connection could not be established');
    }

    $stmt = $plain->prepare("SHOW VARIABLES LIKE 'have_ssl'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $plain->close();

    if (($rows[0]['Value'] ?? '') !== 'YES') {
        $this->markTestSkipped('MariaDB server does not have TLS enabled');
    }

    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mariadbConfig() + [
        'options' => [Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false],
    ]);

    $stmt = $pdo->prepare("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Value'])->not->toBe('');

    $pdo->close();
});
