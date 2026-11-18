<?php

declare(strict_types=1);

use Fledge\Async\CancelledException;
use Fledge\Async\Cancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\Parallel\Context\Internal\AbstractContext;
use Fledge\Async\Parallel\Context\Internal\ExitSuccess;
use Fledge\Async\Sync\Channel;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Sync\createChannelPair;

/*
 * receiveExitResult() keeps the receive of the exit result in a property that only a cancelled join clears.
 * A join that finds it completed must take its result (or start a new receive after a cancelled one) instead
 * of awaiting the same completed future again, which returns at once and would spin. The context runs over an
 * in-memory channel pair; the test plays the child and sends the exit result.
 */

function joinTestContext(): array
{
    [$ipc, $ipcChild] = createChannelPair();
    [$result, $resultChild] = createChannelPair();

    $context = new class($ipc, $result) extends AbstractContext
    {
        public function __construct(Channel $ipc, Channel $result)
        {
            parent::__construct($ipc, $result);
        }

        public function join(?Cancellation $cancellation = null): mixed
        {
            return $this->receiveExitResult($cancellation)->getResult();
        }
    };

    return [$context, $resultChild];
}

/** Fails the test instead of spinning for ever when a loop never yields. */
function joinTestWatchdog(Closure $body): mixed
{
    if (! function_exists('pcntl_alarm')) {
        return $body();
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): never {
        throw new RuntimeException('watchdog: join did not finish within 10 seconds (livelock)');
    });
    pcntl_alarm(10);

    try {
        return $body();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}

it('returns the exit result to a join after an earlier join completed', function () {
    [$context, $child] = joinTestContext();

    $results = joinTestWatchdog(function () use ($context, $child): array {
        return async(function () use ($context, $child): array {
            async(fn () => $child->send(new ExitSuccess(42)))->ignore();

            return [$context->join(), $context->join()];
        })->await();
    });

    expect($results)->toBe([42, 42]);
});

it('returns the exit result to concurrent joins', function () {
    [$context, $child] = joinTestContext();

    $results = joinTestWatchdog(function () use ($context, $child): array {
        $first = async(fn () => $context->join());
        $second = async(fn () => $context->join());

        delay(0.01);
        $child->send(new ExitSuccess('done'));

        return [$first->await(), $second->await()];
    });

    expect($results)->toBe(['done', 'done']);
});

it('starts a new receive for a join arriving right after a cancelled join', function () {
    [$context, $child] = joinTestContext();
    $deferred = new DeferredCancellation;

    $results = joinTestWatchdog(function () use ($context, $child, $deferred): array {
        $cancelled = async(function () use ($context, $deferred): string {
            try {
                $context->join($deferred->getCancellation());
            } catch (CancelledException) {
                return 'cancelled';
            }

            return 'joined';
        });

        delay(0.01); // The cancelled join is now receiving.

        $late = async(function () use ($context, $deferred): mixed {
            $deferred->cancel();

            // Queued right behind the cancelled receive: runs once that receive failed, before its join resumed.
            return async(fn () => $context->join())->await();
        });

        delay(0.01);
        $child->send(new ExitSuccess('late'));

        return [$cancelled->await(), $late->await()];
    });

    expect($results)->toBe(['cancelled', 'late']);
});
