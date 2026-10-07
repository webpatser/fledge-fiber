<?php

use Fledge\Async\Database\Postgres\Internal\PostgresConnectionTransaction;
use Fledge\Async\Database\Postgres\Internal\PostgresHandle;
use Fledge\Async\Database\SqlTransactionIsolationLevel;

use function Fledge\Async\async;
use function Fledge\Async\delay;

afterEach(fn () => Mockery::close());

function closedTransactionFixture(PostgresHandle $handle, int &$released): PostgresConnectionTransaction
{
    return new PostgresConnectionTransaction(
        $handle,
        function () use (&$released): void {
            $released++;
        },
        SqlTransactionIsolationLevel::Committed,
    );
}

it('destructs an active transaction on a closed executor without an uncaught loop error', function () {
    $handle = Mockery::mock(PostgresHandle::class);
    $handle->shouldReceive('isClosed')->andReturn(true);
    $handle->shouldNotReceive('rollback');

    $released = 0;
    $transaction = closedTransactionFixture($handle, $released);
    unset($transaction);

    delay(0.01);

    expect($released)->toBe(1);
});

it('swallows any failure of the background rollback when the connection dies mid-rollback', function () {
    $handle = Mockery::mock(PostgresHandle::class);
    $handle->shouldReceive('isClosed')->andReturn(false);
    $handle->shouldReceive('rollback')->once()->andThrow(new Error('The connection to the database has been closed'));

    $released = 0;
    $transaction = closedTransactionFixture($handle, $released);
    unset($transaction);

    delay(0.01);

    expect($released)->toBe(1);
});

it('settles each future once when two fibers roll back concurrently', function () {
    $handle = Mockery::mock(PostgresHandle::class);
    $handle->shouldReceive('isClosed')->andReturn(false);
    $handle->shouldReceive('rollback')->twice()->andReturnUsing(fn () => delay(0.01));

    $released = 0;
    $transaction = closedTransactionFixture($handle, $released);
    $closed = 0;
    $transaction->onClose(function () use (&$closed): void {
        $closed++;
    });

    $first = async(fn () => $transaction->rollback());
    $second = async(fn () => $transaction->rollback());
    $first->await();
    $second->await();
    delay(0.01);

    expect($transaction->isClosed())->toBeTrue()
        ->and($transaction->isActive())->toBeFalse()
        ->and($closed)->toBe(1)
        ->and($released)->toBe(1);
});
