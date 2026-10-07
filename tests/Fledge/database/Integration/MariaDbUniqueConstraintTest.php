<?php

use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class FledgeUniqueConstraintUser extends Model
{
    protected $table = '_fledge_unique_users';

    protected $guarded = [];

    public $timestamps = false;
}

uses()->beforeEach(function () {
    if (! mariadbAvailable()) {
        $this->markTestSkipped('MariaDB not available on port '.test_env('FLEDGE_TEST_MARIADB_PORT', 13307));
    }

    $pdo = mariadbConnection();

    if (! $pdo instanceof FledgeMySqlPdo) {
        $this->markTestSkipped('MariaDB connection could not be established');
    }

    $config = mariadbConfig();
    $this->pdo = $pdo;
    $this->connection = new FledgeMariaDbConnection($pdo, $config['database'], '', $config + ['name' => 'fledge-mariadb']);

    $this->pdo->exec('DROP TABLE IF EXISTS _fledge_unique_users');
    $this->pdo->exec('CREATE TABLE _fledge_unique_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(191) NOT NULL, name VARCHAR(191) NULL, UNIQUE KEY _fledge_unique_users_email_unique (email))');
})->afterEach(function () {
    if (isset($this->pdo)) {
        $this->pdo->exec('DROP TABLE IF EXISTS _fledge_unique_users');
        $this->pdo->close();
    }

    Model::unsetConnectionResolver();
});

it('throws UniqueConstraintViolationException with the index on a duplicate insert', function () {
    $this->connection->table('_fledge_unique_users')->insert(['email' => 'a@example.com']);

    try {
        $this->connection->table('_fledge_unique_users')->insert(['email' => 'a@example.com']);
        $this->fail('Expected a UniqueConstraintViolationException');
    } catch (UniqueConstraintViolationException $e) {
        expect($e->index)->toBe('_fledge_unique_users_email_unique')
            ->and($e->columns)->toBe([])
            ->and($e->getCode())->toBe('23000')
            ->and($e->errorInfo)->toBe(['23000', 1062, "Duplicate entry 'a@example.com' for key '_fledge_unique_users_email_unique'"])
            ->and($e->getPrevious())->toBeInstanceOf(PDOException::class);
    }
});

it('returns the existing row from createOrFirst', function () {
    Model::setConnectionResolver(new ConnectionResolver(['fledge-mariadb' => $this->connection]));
    Model::getConnectionResolver()->setDefaultConnection('fledge-mariadb');

    $original = FledgeUniqueConstraintUser::create(['email' => 'b@example.com', 'name' => 'Original']);

    $found = FledgeUniqueConstraintUser::createOrFirst(['email' => 'b@example.com'], ['name' => 'Second']);

    expect($found->wasRecentlyCreated)->toBeFalse()
        ->and($found->id)->toEqual($original->id)
        ->and($found->name)->toBe('Original')
        ->and($this->connection->table('_fledge_unique_users')->count())->toBe(1);
});
