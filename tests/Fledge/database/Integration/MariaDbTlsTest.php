<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Pdo\Mysql;

uses()->beforeEach(function () {
    if (! mariadbAvailable()) {
        $this->markTestSkipped('MariaDB not available on port '.test_env('FLEDGE_TEST_MARIADB_PORT', 13307));
    }
})->afterEach(fn () => nativeDriverReset());

it('negotiates tls when ssl options are configured', function (string $driver) {
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

    $options = ['options' => [Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]];

    if ($driver === 'native') {
        // pdo_mysql only requests TLS when an SSL option such as a cipher list is set; verify=false alone stays plain.
        $connection = nativeDriverConnection('mariadb', ['options' => $options['options'] + [Mysql::ATTR_SSL_CIPHER => 'DEFAULT']]);
        $rows = array_map(fn (object $row) => (array) $row, $connection->select("SHOW SESSION STATUS LIKE 'Ssl_cipher'"));
        $connection->disconnect();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['Value'])->not->toBe('');

        return;
    }

    $connector = new FledgeMySqlConnector;
    $pdo = $connector->connect(mariadbConfig() + $options);

    $stmt = $pdo->prepare("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Value'])->not->toBe('');

    $pdo->close();
})->with(['fledge', 'native']);
