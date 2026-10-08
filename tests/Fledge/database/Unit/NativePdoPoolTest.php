<?php

use Fledge\Fiber\Database\Native\GuardedPdo;
use Fledge\Fiber\Database\Native\LeaseGuard;
use Fledge\Fiber\Database\Native\NativePdoPool;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * NativePdoPool against PDO doubles: no server needed.
 */

/**
 * Guarded PDO double carrying an id; it never talks to a server.
 */
function nativePoolFakePdo(int $id): PDO
{
    return new class($id) extends PDO implements GuardedPdo
    {
        public bool $inTx = false;

        private LeaseGuard $guard;

        public function __construct(public int $id)
        {
            $this->guard = new LeaseGuard;
        }

        public function leaseGuard(): LeaseGuard
        {
            return $this->guard;
        }

        public function inTransaction(): bool
        {
            return $this->inTx;
        }
    };
}

it('opens connections lazily and never more than the max size', function () {
    $opened = 0;
    $pool = new NativePdoPool(function () use (&$opened) {
        return nativePoolFakePdo(++$opened);
    }, 2, 60.0);

    expect($opened)->toBe(0)->and($pool)->toHaveCount(0);

    $a = $pool->acquire();
    $b = $pool->acquire();

    expect($opened)->toBe(2)
        ->and($pool)->toHaveCount(2)
        ->and($pool->getLeasedCount())->toBe(2);

    $pool->release($a);
    $c = $pool->acquire();

    expect($c)->toBe($a)
        ->and($opened)->toBe(2)
        ->and($pool->getMaxSize())->toBe(2);

    $pool->release($b);
    $pool->release($c);

    expect($pool->getIdleCount())->toBe(2)->and($pool->getLeasedCount())->toBe(0);
});

it('parks fibers on an exhausted pool and serves them first in, first out', function () {
    $opened = 0;
    $pool = new NativePdoPool(function () use (&$opened) {
        return nativePoolFakePdo(++$opened);
    }, 2, 60.0);

    $order = [];
    $maxLeased = 0;
    $waitingAtFirstRelease = null;

    $workers = [];

    for ($i = 0; $i < 5; $i++) {
        $workers[] = async(function () use ($pool, $i, &$order, &$maxLeased, &$waitingAtFirstRelease) {
            $pdo = $pool->acquire();
            $order[] = $i;
            $maxLeased = max($maxLeased, $pool->getLeasedCount());

            delay(0.01);

            $waitingAtFirstRelease ??= $pool->getWaitingCount();
            $pool->release($pdo);

            return $pdo->id;
        });
    }

    $ids = await($workers);

    expect($order)->toBe([0, 1, 2, 3, 4])
        ->and($opened)->toBe(2)
        ->and($maxLeased)->toBe(2)
        ->and($waitingAtFirstRelease)->toBe(3)
        // Released connections are handed straight to the oldest waiter.
        ->and($ids)->toBe([1, 2, 1, 2, 1])
        ->and($pool->getWaitingCount())->toBe(0)
        ->and($pool)->toHaveCount(2);
});

it('passes the slot of a discarded connection to the oldest waiter', function () {
    $opened = 0;
    $pool = new NativePdoPool(function () use (&$opened) {
        return nativePoolFakePdo(++$opened);
    }, 1, 60.0);

    $first = async(function () use ($pool) {
        $pdo = $pool->acquire();
        delay(0.01);
        $pool->discard($pdo);

        return $pdo->id;
    });

    $second = async(function () use ($pool) {
        $pdo = $pool->acquire();
        $pool->release($pdo);

        return $pdo->id;
    });

    expect(await([$first, $second]))->toBe([1, 2])
        ->and($opened)->toBe(2)
        ->and($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1);
});

it('discards connections that are broken or still inside a transaction on release', function () {
    $opened = 0;
    $pool = new NativePdoPool(function () use (&$opened) {
        return nativePoolFakePdo(++$opened);
    }, 4, 60.0);

    $broken = $pool->acquire();
    $inTransaction = $pool->acquire();
    $healthy = $pool->acquire();

    $broken->leaseGuard()->markBroken();
    $inTransaction->inTx = true;

    $pool->release($broken);
    $pool->release($inTransaction);
    $pool->release($healthy);

    expect($pool)->toHaveCount(1)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool->acquire())->toBe($healthy);
});

it('rejects releasing a connection twice or one it never leased', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 2, 60.0);

    $pdo = $pool->acquire();
    $pool->release($pdo);

    expect(fn () => $pool->release($pdo))->toThrow(LogicException::class, 'not leased from this pool')
        ->and(fn () => $pool->discard(nativePoolFakePdo(9)))->toThrow(LogicException::class, 'not leased from this pool');
});

it('reaps idle connections once they exceed the idle timeout', function () {
    $now = 0.0;
    $opened = 0;
    $pool = new NativePdoPool(
        function () use (&$opened) {
            return nativePoolFakePdo(++$opened);
        },
        3,
        8.0,
        function () use (&$now) {
            return $now;
        },
    );

    $a = $pool->acquire();
    $b = $pool->acquire();

    $pool->release($a);          // idle since t=0
    $now = 5.0;
    $pool->release($b);          // idle since t=5

    $now = 10.0;

    expect($pool->reap())->toBe(1)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool)->toHaveCount(1);

    $now = 20.0;
    $c = $pool->acquire();       // reaps b first, then opens a fresh one

    expect($c->id)->toBe(3)
        ->and($opened)->toBe(3)
        ->and($pool)->toHaveCount(1);
});

it('frees the slot when the factory fails', function () {
    $attempts = 0;
    $pool = new NativePdoPool(function () use (&$attempts) {
        if (++$attempts === 1) {
            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        }

        return nativePoolFakePdo($attempts);
    }, 1, 60.0);

    expect(fn () => $pool->acquire())->toThrow(PDOException::class, 'Connection refused')
        ->and($pool)->toHaveCount(0)
        ->and($pool->acquire()->id)->toBe(2);
});

it('throws into waiting fibers when the pool is closed', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0);

    $held = $pool->acquire();
    $waiter = async(fn () => $pool->acquire());

    delay(0.001);

    expect($pool->getWaitingCount())->toBe(1);

    $pool->close();

    expect(fn () => $waiter->await())->toThrow(RuntimeException::class, 'closed while waiting')
        ->and(fn () => $pool->acquire())->toThrow(RuntimeException::class, 'pool is closed');

    $pool->release($held);

    expect($pool)->toHaveCount(0)->and($pool->isClosed())->toBeTrue();
});

it('rejects a wait timeout that is not positive', function (float $timeout) {
    expect(fn () => new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0, null, $timeout))
        ->toThrow(InvalidArgumentException::class, 'pool_wait_timeout must be greater than 0');
})->with([
    'zero' => 0.0,
    'negative' => -1.0,
]);

it('times out a waiter on an exhausted pool with a leaked-lease hint', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0, waitTimeout: 0.05);

    $held = $pool->acquire();

    $start = hrtime(true);
    $waiter = async(fn () => $pool->acquire());

    expect(fn () => $waiter->await())->toThrow(RuntimeException::class, 'Timed out after 0.05s waiting for a connection');

    $elapsed = (hrtime(true) - $start) / 1e9;

    expect($elapsed)->toBeGreaterThanOrEqual(0.045)
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($pool->getWaitingCount())->toBe(0)
        ->and($pool->getWaitTimeout())->toBe(0.05);

    $pool->release($held);
});

it('names pool size, waiters and leaked leases in the timeout message', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0, waitTimeout: 0.05);

    $held = $pool->acquire();
    $first = async(fn () => $pool->acquire());
    $second = async(fn () => $pool->acquire());

    try {
        $first->await();
        $this->fail('Expected the wait to time out.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('1 of 1 connections leased')
            ->toContain('1 other fibers still waiting')
            ->toContain('transaction awaiting child fibers')
            ->toContain('cursor()');
    }

    expect(fn () => $second->await())->toThrow(RuntimeException::class, '0 other fibers still waiting');

    $pool->release($held);
});

it('never hands a later released connection to a waiter that timed out', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0, waitTimeout: 0.05);

    $held = $pool->acquire();
    $waiter = async(fn () => $pool->acquire());

    delay(0.1);

    $pool->release($held);

    expect(fn () => $waiter->await())->toThrow(RuntimeException::class, 'Timed out')
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool->getIdleCount())->toBe(1)
        ->and($pool->acquire())->toBe($held);
});

it('keeps first in, first out order for the waiters left after one timed out', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0, waitTimeout: 0.2);

    $held = $pool->acquire();
    $order = [];

    $worker = function (string $name) use ($pool, &$order) {
        return async(function () use ($pool, $name, &$order) {
            $pdo = $pool->acquire();
            $order[] = $name;
            $pool->release($pdo);

            return $pdo->id;
        });
    };

    $early = $worker('early');          // times out at t=0.2

    delay(0.1);

    $second = $worker('second');        // would time out at t=0.3
    $third = $worker('third');

    delay(0.15);                        // t=0.25: early is gone, second and third still queued

    expect(fn () => $early->await())->toThrow(RuntimeException::class, 'Timed out')
        ->and($pool->getWaitingCount())->toBe(2);

    $pool->release($held);

    expect(await([$second, $third]))->toBe([1, 1])
        ->and($order)->toBe(['second', 'third']);

    // Served waiters cancelled their timers: nothing fires into them later.
    delay(0.25);

    expect($pool->getWaitingCount())->toBe(0)
        ->and($pool->getLeasedCount())->toBe(0)
        ->and($pool->getIdleCount())->toBe(1);
});

it('arms the lease guard for the acquiring fiber and clears it on release', function () {
    $pool = new NativePdoPool(fn () => nativePoolFakePdo(1), 1, 60.0);

    [$owner, $fiber] = async(function () use ($pool) {
        $pdo = $pool->acquire();
        $owner = $pdo->leaseGuard()->owner();
        $fiber = Fiber::getCurrent();
        $pool->release($pdo);

        return [$owner, $fiber];
    })->await();

    $pdo = $pool->acquire();

    expect($owner)->toBe($fiber)
        ->and($pdo->leaseGuard()->owner())->toBe(LeaseGuard::currentOwner());

    $pool->release($pdo);

    expect($pdo->leaseGuard()->owner())->toBeNull()
        ->and(fn () => $pdo->leaseGuard()->assertOwner())->toThrow(LogicException::class, 'released back');
});
