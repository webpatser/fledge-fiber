<?php

use Fledge\Fiber\Database\Native\RevoltWaiter;

/**
 * The loop-fiber path of RevoltWaiter blocks on one stream outside the event
 * loop. It used stream_select(), which fails for any fd >= FD_SETSIZE (1024):
 * the call returned false at once, so fiberio saw an immediate timeout. These
 * tests push a socket pair above fd 1024 and wait on it there.
 */
beforeEach(function () {
    if (! extension_loaded('fiberio') || ! defined('FiberIo\\READABLE')) {
        $this->markTestSkipped('needs php-fiberio');
    }

    $this->filler = [];

    if (function_exists('posix_getrlimit') && function_exists('posix_setrlimit')) {
        $limits = posix_getrlimit();
        $soft = $limits['soft openfiles'];
        $hard = $limits['hard openfiles'];

        if ($soft !== 'unlimited' && (int) $soft < 1200) {
            $target = $hard === 'unlimited' ? 1200 : min(1200, (int) $hard);
            @posix_setrlimit(POSIX_RLIMIT_NOFILE, $target, $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $hard);
        }
    }

    for ($i = 0; $i < 1100; $i++) {
        $handle = @fopen('/dev/null', 'r');

        if ($handle === false) {
            $this->filler = [];
            $this->markTestSkipped('cannot hold 1100 descriptors: raise the open files limit (ulimit -n) above 1200');
        }

        $this->filler[] = $handle;
    }

    $fdDir = is_dir('/proc/self/fd') ? '/proc/self/fd' : '/dev/fd';
    $before = array_filter(scandir($fdDir), 'is_numeric');

    [$this->ours, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    // Precondition: both new descriptors sit at or above FD_SETSIZE, where stream_select() fails.
    $new = array_map('intval', array_diff(array_filter(scandir($fdDir), 'is_numeric'), $before));
    expect($new)->toHaveCount(2)
        ->and(min($new))->toBeGreaterThanOrEqual(1024);
});

afterEach(function () {
    $this->filler = [];
    $this->ours = null;
    $this->peer = null;
});

function revoltWaiterLoopFiberWait($stream, int $events, ?float $timeout): bool
{
    return (fn () => self::waitInLoopFiber($stream, $events, $timeout))->call(new RevoltWaiter);
}

it('reports a readable socket above fd 1024 as ready', function () {
    fwrite($this->peer, 'x');

    expect(revoltWaiterLoopFiberWait($this->ours, \FiberIo\READABLE, 1.0))->toBeTrue()
        ->and(revoltWaiterLoopFiberWait($this->ours, \FiberIo\READABLE, null))->toBeTrue();
});

it('reports a writable socket above fd 1024 as ready', function () {
    expect(revoltWaiterLoopFiberWait($this->ours, \FiberIo\WRITABLE, 1.0))->toBeTrue();
});

it('waits the full timeout on an idle socket above fd 1024', function () {
    $start = hrtime(true);
    $ready = revoltWaiterLoopFiberWait($this->ours, \FiberIo\READABLE, 0.1);
    $elapsed = (hrtime(true) - $start) / 1e9;

    expect($ready)->toBeFalse()
        ->and($elapsed)->toBeGreaterThanOrEqual(0.09)
        ->and($elapsed)->toBeLessThan(1.0)
        ->and(revoltWaiterLoopFiberWait($this->ours, \FiberIo\READABLE, 0.0))->toBeFalse();
});

it('reports a hung-up peer above fd 1024 as ready', function () {
    fclose($this->peer);

    expect(revoltWaiterLoopFiberWait($this->ours, \FiberIo\READABLE, 1.0))->toBeTrue();
});
