<?php

use Fledge\Async\Database\Postgres\PostgresByteA;
use Fledge\Async\Database\SqlConnectionPool;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Fledge\Fiber\Database\Pdo\FledgePostgresPdo;

afterEach(fn () => Mockery::close());

function lobBinding(FledgePdoStatement $stmt, mixed $value): mixed
{
    $stmt->bindValue(1, $value, PDO::PARAM_LOB);

    return (new ReflectionProperty($stmt, 'bindings'))->getValue($stmt)[1];
}

function lobStream(string $bytes): mixed
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('reads a PARAM_LOB stream into a string for MySQL', function () {
    $stmt = new FledgePdoStatement(pdo: new FledgeMySqlPdo(Mockery::mock(SqlConnectionPool::class)));

    expect(lobBinding($stmt, lobStream("\x00\xffbinary")))->toBe("\x00\xffbinary");
});

it('reads from the current stream position like PDO', function () {
    $stream = lobStream('skip-keep');
    fseek($stream, 5);

    $stmt = new FledgePdoStatement(pdo: new FledgeMySqlPdo(Mockery::mock(SqlConnectionPool::class)));

    expect(lobBinding($stmt, $stream))->toBe('keep');
});

it('sends PARAM_LOB as bytea for Postgres', function (mixed $value) {
    $stmt = new FledgePdoStatement(pdo: new FledgePostgresPdo(Mockery::mock(SqlConnectionPool::class)));
    $bound = lobBinding($stmt, $value);

    expect($bound)->toBeInstanceOf(PostgresByteA::class)
        ->and($bound->getData())->toBe("\x00\xffbinary");
})->with([
    'stream' => [fn () => lobStream("\x00\xffbinary")],
    'string' => [fn () => "\x00\xffbinary"],
]);

it('keeps a null PARAM_LOB null', function () {
    $stmt = new FledgePdoStatement(pdo: new FledgePostgresPdo(Mockery::mock(SqlConnectionPool::class)));

    expect(lobBinding($stmt, null))->toBeNull();
});

it('rejects a PARAM_LOB resource that is not an open stream like PDO', function (Closure $resource) {
    $stmt = new FledgePdoStatement(pdo: new FledgeMySqlPdo(Mockery::mock(SqlConnectionPool::class)));

    try {
        $stmt->bindValue(1, $resource(), PDO::PARAM_LOB);
        $this->fail('Expected a PDOException');
    } catch (PDOException $e) {
        expect($e->getMessage())->toBe('SQLSTATE[HY105]: Invalid parameter type: Expected a stream resource')
            ->and($e->getCode())->toBe('HY105');
    }
})->with([
    'closed stream' => [fn () => tap(lobStream('gone'), fn ($stream) => fclose($stream))],
    'stream context' => [fn () => stream_context_create()],
]);

it('shapes LOB bytes for the driver given to a detached statement', function () {
    $bound = lobBinding(new FledgePdoStatement(driver: 'pgsql'), lobStream('raw'));

    expect($bound)->toBeInstanceOf(PostgresByteA::class)
        ->and($bound->getData())->toBe('raw');
});
