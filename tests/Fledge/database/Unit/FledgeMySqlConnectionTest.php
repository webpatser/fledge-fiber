<?php

use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;

afterEach(fn () => Mockery::close());

it('isMaria returns true for MariaDB version string', function () {
    $mockPdo = Mockery::mock(PDO::class);
    $mockPdo->shouldReceive('getAttribute')
        ->with(PDO::ATTR_SERVER_VERSION)
        ->andReturn('5.5.5-10.6.12-MariaDB');

    $ref = new ReflectionClass(FledgeMySqlConnection::class);
    $conn = $ref->newInstanceWithoutConstructor();

    // Set the PDO mock
    $pdoProp = $ref->getProperty('pdo');
    $pdoProp->setValue($conn, $mockPdo);

    expect($conn->isMaria())->toBeTrue();
});

it('isMaria returns false for regular MySQL', function () {
    $mockPdo = Mockery::mock(PDO::class);
    $mockPdo->shouldReceive('getAttribute')
        ->with(PDO::ATTR_SERVER_VERSION)
        ->andReturn('8.0.35');

    $ref = new ReflectionClass(FledgeMySqlConnection::class);
    $conn = $ref->newInstanceWithoutConstructor();

    $pdoProp = $ref->getProperty('pdo');
    $pdoProp->setValue($conn, $mockPdo);

    expect($conn->isMaria())->toBeFalse();
});

it('extends MySqlConnection', function () {
    expect(is_subclass_of(FledgeMySqlConnection::class, \Illuminate\Database\MySqlConnection::class))
        ->toBeTrue();
});
