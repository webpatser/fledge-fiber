<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Fledge\Fiber\Database\Pdo\FledgePdo;
use Revolt\EventLoop;

/**
 * Regression tests for the pool_size => 1 statement deadlock: a pooled prepared
 * statement whose retention was declined used to keep its connection checked out
 * for as long as the caller held the statement, so the next prepare+execute
 * waited forever. The watchdog closes the pool so a regression fails loudly
 * instead of hanging the suite.
 */
function withDeadlockWatchdog(FledgePdo $pdo, Closure $test): void
{
    $watchdog = EventLoop::delay(10, static fn () => $pdo->close());

    try {
        $test($pdo);
    } finally {
        EventLoop::cancel($watchdog);
        $pdo->close();
    }
}

function assertSequentialPreparesWork(FledgePdo $pdo): void
{
    withDeadlockWatchdog($pdo, function (FledgePdo $pdo): void {
        $stmt1 = $pdo->prepare('SELECT 1 AS n');
        $stmt1->execute();
        $rows1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        // $stmt1 stays in scope, pinning its result like any real PDO consumer.
        $stmt2 = $pdo->prepare('SELECT 2 AS n');
        $stmt2->execute();
        $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        expect($rows1[0]['n'])->toEqual(1)
            ->and($rows2[0]['n'])->toEqual(2);
    });
}

function assertReExecuteWorks(FledgePdo $pdo): void
{
    withDeadlockWatchdog($pdo, function (FledgePdo $pdo): void {
        $stmt = $pdo->prepare('SELECT 3 AS n');

        $stmt->execute();
        $first = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt->execute();
        $second = $stmt->fetchAll(PDO::FETCH_ASSOC);

        expect($first[0]['n'])->toEqual(3)
            ->and($second[0]['n'])->toEqual(3);
    });
}

it('runs sequential prepared statements on a single-connection postgres pool', function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('PostgreSQL not available');
    }

    $pdo = (new FledgePostgresConnector)->connect(['pool_size' => 1] + postgresConfig());

    assertSequentialPreparesWork($pdo);
});

it('re-executes a prepared statement on a single-connection postgres pool', function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('PostgreSQL not available');
    }

    $pdo = (new FledgePostgresConnector)->connect(['pool_size' => 1] + postgresConfig());

    assertReExecuteWorks($pdo);
});

it('runs sequential prepared statements on a single-connection mysql pool', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available');
    }

    $pdo = (new FledgeMySqlConnector)->connect(['pool_size' => 1] + mysqlConfig());

    assertSequentialPreparesWork($pdo);
});

it('re-executes a prepared statement on a single-connection mysql pool', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('MySQL not available');
    }

    $pdo = (new FledgeMySqlConnector)->connect(['pool_size' => 1] + mysqlConfig());

    assertReExecuteWorks($pdo);
});
