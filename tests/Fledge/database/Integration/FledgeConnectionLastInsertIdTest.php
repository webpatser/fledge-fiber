<?php

use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * Laravel's MySqlConnection::insert() stores the insert id on the connection
 * and MySqlProcessor::processInsertGetId() reads it back after run() fired
 * QueryExecuted. A listener that suspends in between lets another fiber insert
 * on the same connection object; stock pdo_mysql has one owner per connection,
 * so insertGetId() always returns that caller's own id.
 */
function lastInsertIdConnection(string $flavor): Connection
{
    $config = $flavor === 'mariadb' ? mariadbConfig() : mysqlConfig();
    $class = $flavor === 'mariadb' ? FledgeMariaDbConnection::class : FledgeMySqlConnection::class;

    return new $class(
        (new FledgeMySqlConnector)->connect($config),
        $config['database'],
        '',
        $config + ['name' => "fledge-{$flavor}"],
    );
}

afterEach(function () {
    if (isset($this->connection)) {
        $this->connection->getPdo()->exec('DROP TABLE IF EXISTS _fledge_last_insert_ids');
        $this->connection->getPdo()->close();
    }
});

it('keeps the insertGetId() result per fiber when a listener suspends between insert and id read', function (string $flavor) {
    if (! ($flavor === 'mariadb' ? mariadbAvailable() : mysqlAvailable())) {
        $this->markTestSkipped("{$flavor} not available");
    }

    $this->connection = $connection = lastInsertIdConnection($flavor);
    $connection->getPdo()->exec('DROP TABLE IF EXISTS _fledge_last_insert_ids');
    $connection->getPdo()->exec('CREATE TABLE _fledge_last_insert_ids (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(32) NOT NULL)');

    $connection->setEventDispatcher(new Dispatcher);
    $connection->listen(function ($query): void {
        if (str_starts_with($query->sql, 'insert')) {
            delay(0.05);
        }
    });

    $insert = static function (string $label) use ($connection): array {
        $id = $connection->table('_fledge_last_insert_ids')->insertGetId(['label' => $label]);

        return [$id, $connection->table('_fledge_last_insert_ids')->where('id', $id)->value('label')];
    };

    [$first, $second] = await([
        async($insert, 'first'),
        async(static function () use ($insert): array {
            delay(0.01);

            return $insert('second');
        }),
    ]);

    expect($first[1])->toBe('first')
        ->and($second[1])->toBe('second')
        ->and($first[0])->not->toBe($second[0]);
})->with(['mysql', 'mariadb']);
