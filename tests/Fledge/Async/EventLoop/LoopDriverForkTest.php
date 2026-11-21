<?php

declare(strict_types=1);

use Fledge\Async\EventLoop\Driver\PollDriver;
use Fledge\Async\EventLoop\LoopDriver;
use Revolt\EventLoop\Driver\StreamSelectDriver;

/*
 * LoopDriver::afterFork() and the PollDriver fork guard, with real pcntl_fork() calls. The test runner never forks:
 * every test starts a fresh PHP process, which plays the parent (installs a driver, watches a stream, awaits from
 * {main}, registers a loop signal handler) and forks a child. The child reports through a socket pair, the parent
 * prints one JSON line. A parent that crashes (the shared epoll descriptor used to make it segfault) prints nothing.
 */

/**
 * @param  array<string, mixed>  $vars  Variables defined (via var_export) before the code runs.
 * @return array<string, mixed>
 */
function loopDriverForkScenario(string $code, array $vars = []): array
{
    $autoload = dirname(__DIR__, 4).'/vendor/autoload.php';
    $prelude = 'require '.var_export($autoload, true).";\n";

    foreach ($vars as $name => $value) {
        $prelude .= '$'.$name.' = '.var_export($value, true).";\n";
    }

    $env = getenv();
    unset($env[LoopDriver::ENV]);

    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $prelude.$code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = json_decode($stdout, true);

    if (! is_array($decoded) || $exitCode !== 0) {
        test()->fail("parent process failed (exit {$exitCode}). stdout: {$stdout} stderr: {$stderr}");
    }

    return $decoded;
}

/*
 * Parent: $parentDriver with a watched socket pair, a loop signal handler and an await from {main}, then fork.
 * Child ($mode): 'afterFork' calls LoopDriver::afterFork($childDriver); 'afterFork+close' also closes the
 * parent's watched stream; 'inherited' keeps using the inherited driver. Then the child does its own socket pair
 * I/O and timers. The parent writes to its stream while the child runs and once more after the child exited.
 */
const LOOP_DRIVER_FORK_IO = <<<'PHP'
    use Fledge\Async\EventLoop\LoopDriver;
    use Revolt\EventLoop;

    LoopDriver::install($parentDriver);
    EventLoop::unreference(EventLoop::onSignal(SIGUSR1, static fn () => null));
    [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $parentGot = '';
    EventLoop::unreference(EventLoop::onReadable($watched, function () use ($watched, &$parentGot) {
        $parentGot .= fread($watched, 100);
    }));
    Fledge\Async\delay(0.01);
    [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $pid = pcntl_fork();

    if ($pid === 0) {
        $out = [];

        try {
            if ($mode !== 'inherited') {
                $driver = LoopDriver::afterFork($childDriver);
                $out['class'] = $driver::class;
                $out['installed'] = EventLoop::getDriver() === $driver;
                $out['callbacks'] = count(EventLoop::getIdentifiers());
                $out['sigusr1Default'] = pcntl_signal_get_handler(SIGUSR1) === SIG_DFL;
            }

            if ($mode === 'afterFork+close') {
                fclose($watched);
            }

            [$own, $ownPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $shift = [];
            for ($i = 0; $i < 8; $i++) {
                $shift[] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            }
            fwrite($ownPeer, 'job');
            $childGot = '';
            $ticks = 0;
            $readId = EventLoop::onReadable($own, function () use ($own, &$childGot) {
                $childGot .= fread($own, 100);
            });
            $timerId = EventLoop::repeat(0.01, function () use (&$ticks) {
                $ticks++;
            });
            Fledge\Async\delay(0.2);
            EventLoop::cancel($readId);
            EventLoop::cancel($timerId);
            $out['got'] = $childGot;
            $out['ticked'] = $ticks > 0;
        } catch (Throwable $exception) {
            $out['error'] = $exception::class;
            $out['message'] = $exception->getMessage();
        }

        fwrite($reportPeer, json_encode($out)."\n");
        exit(0);
    }

    Fledge\Async\delay(0.05);
    fwrite($peer, 'hello');
    Fledge\Async\delay(0.3);
    pcntl_waitpid($pid, $status);
    fwrite($peer, ' again');
    Fledge\Async\delay(0.05);

    echo json_encode([
        'parentGot' => $parentGot,
        'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
        'child' => json_decode(fgets($report), true),
    ]);
    PHP;

$requiresFork = ! extension_loaded('pcntl') || ! extension_loaded('posix') || ! function_exists('pcntl_fork');
$revoltClass = (new Revolt\EventLoop\DriverFactory())->create()::class;

it('gives a forked child a fresh empty loop while the parent keeps only its own events', function (
    string $parentDriver,
    string $childDriver,
    string $class,
) {
    $result = loopDriverForkScenario(LOOP_DRIVER_FORK_IO, [
        'parentDriver' => $parentDriver,
        'childDriver' => $childDriver,
        'mode' => 'afterFork',
    ]);

    expect($result['parentGot'])->toBe('hello again')
        ->and($result['childExit'])->toBe(0)
        ->and($result['child'])->toBe([
            'class' => $class,
            'installed' => true,
            'callbacks' => 0,
            'sigusr1Default' => true,
            'got' => 'job',
            'ticked' => true,
        ]);
})->with([
    'poll parent, poll child' => ['poll', 'poll', PollDriver::class],
    'poll parent, select child' => ['poll', 'select', StreamSelectDriver::class],
    'poll parent, auto child' => ['poll', 'auto', PollDriver::class],
    'poll parent, revolt child' => ['poll', 'revolt', $revoltClass],
    'select parent, poll child' => ['select', 'poll', PollDriver::class],
    'select parent, select child' => ['select', 'select', StreamSelectDriver::class],
])->skip($requiresFork, 'needs pcntl and posix');

it('keeps the parent watching a stream the child closes after afterFork()', function (string $parentDriver) {
    $result = loopDriverForkScenario(LOOP_DRIVER_FORK_IO, [
        'parentDriver' => $parentDriver,
        'childDriver' => 'poll',
        'mode' => 'afterFork+close',
    ]);

    expect($result['parentGot'])->toBe('hello again')
        ->and($result['child']['got'] ?? null)->toBe('job');
})->with(['poll', 'select'])->skip($requiresFork, 'needs pcntl and posix');

it('refuses the inherited PollDriver in a child without afterFork() and leaves the parent unaffected', function () {
    $result = loopDriverForkScenario(LOOP_DRIVER_FORK_IO, [
        'parentDriver' => 'poll',
        'childDriver' => null,
        'mode' => 'inherited',
    ]);

    expect($result['parentGot'])->toBe('hello again')
        ->and($result['childExit'])->toBe(0)
        ->and($result['child']['error'] ?? null)->toBe(LogicException::class)
        ->and($result['child']['message'])->toContain('used in forked process')
        ->and($result['child']['message'])->toContain('LoopDriver::afterFork()');
})->skip($requiresFork, 'needs pcntl and posix');

it('keeps the parent watching when a child exits without touching the loop', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Revolt\EventLoop;

        Fledge\Async\EventLoop\LoopDriver::install('poll');
        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $parentGot = '';
        EventLoop::unreference(EventLoop::onReadable($watched, function () use ($watched, &$parentGot) {
            $parentGot .= fread($watched, 100);
        }));
        Fledge\Async\delay(0.01);

        $pid = pcntl_fork();

        if ($pid === 0) {
            exit(0); // PHP shutdown closes the inherited streams.
        }

        pcntl_waitpid($pid, $status);
        fwrite($peer, 'hello');
        Fledge\Async\delay(0.1);

        echo json_encode(['parentGot' => $parentGot]);
        PHP);

    expect($result['parentGot'])->toBe('hello');
})->skip($requiresFork, 'needs pcntl and posix');

it('refuses afterFork() inside a fiber and inside an event loop callback', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Revolt\EventLoop;

        $before = Fledge\Async\EventLoop\LoopDriver::install('poll');
        $messages = [];
        $attempt = function (string $where) use (&$messages) {
            try {
                Fledge\Async\EventLoop\LoopDriver::afterFork('select');
                $messages[$where] = null;
            } catch (LogicException $exception) {
                $messages[$where] = $exception->getMessage();
            }
        };

        Fledge\Async\async($attempt, 'fiber')->await();
        EventLoop::defer(fn () => $attempt('callback'));
        EventLoop::run();
        (new Fiber(fn () => $attempt('plain fiber')))->start();

        echo json_encode(['messages' => $messages, 'unchanged' => EventLoop::getDriver() === $before]);
        PHP);

    expect($result['unchanged'])->toBeTrue()
        ->and(array_keys($result['messages']))->toBe(['fiber', 'callback', 'plain fiber']);

    foreach ($result['messages'] as $message) {
        expect($message)->toContain('must be called from {main}');
    }
});

it('points install() in a forked child to afterFork()', function (string $state, string $reason) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Revolt\EventLoop;

        Fledge\Async\EventLoop\LoopDriver::install('poll');

        if ($state === 'awaited') {
            Fledge\Async\delay(0.01);
        } else {
            EventLoop::onSignal(SIGUSR1, static fn () => null);
        }

        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                Fledge\Async\EventLoop\LoopDriver::install('poll');
                $message = null;
            } catch (LogicException $exception) {
                $message = $exception->getMessage();
            }

            fwrite($reportPeer, json_encode(['message' => $message])."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo fgets($report);
        PHP, ['state' => $state]);

    expect($result['message'])->toContain($reason)
        ->and($result['message'])->toContain('inherited through fork()')
        ->and($result['message'])->toContain('LoopDriver::afterFork() in the child');
})->with([
    'parent awaited from {main}' => ['awaited', 'while the event loop is running'],
    'parent registered a signal handler' => ['signal', '1 callback(s) registered'],
])->skip($requiresFork, 'needs pcntl and posix');

it('mentions afterFork() when install() refuses in a process that was not forked', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        Revolt\EventLoop::defer(static fn () => null);

        try {
            Fledge\Async\EventLoop\LoopDriver::install('poll');
            $message = null;
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }

        echo json_encode(['message' => $message]);
        PHP);

    expect($result['message'])->toContain('1 callback(s) registered')
        ->and($result['message'])->toContain('In a child forked from a process that used the event loop');
});

it('lets afterFork() replace an idle driver in a process that never forked', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        $first = Fledge\Async\EventLoop\LoopDriver::install('poll');
        Revolt\EventLoop::delay(10, static fn () => null);
        Fledge\Async\delay(0.01);
        $second = Fledge\Async\EventLoop\LoopDriver::afterFork('select');
        $order = [];
        Revolt\EventLoop::defer(function () use (&$order) {
            $order[] = 'defer';
        });
        Revolt\EventLoop::run();

        echo json_encode([
            'class' => $second::class,
            'installed' => Revolt\EventLoop::getDriver() === $second,
            'order' => $order,
            'oldReleased' => $first->getHandle() === null,
        ]);
        PHP);

    expect($result)->toBe([
        'class' => StreamSelectDriver::class,
        'installed' => true,
        'order' => ['defer'],
        'oldReleased' => true,
    ]);
});

it('keeps parent callback ids from naming the child callbacks after afterFork()', function (
    string $parentDriver,
    string $childDriver,
) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Async\EventLoop\LoopDriver;
        use Revolt\EventLoop;

        LoopDriver::install($parentDriver);
        $parentTimer = EventLoop::repeat(10, static fn () => null); // owned by some parent-built object
        EventLoop::unreference($parentTimer);
        Fledge\Async\delay(0.01);
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = pcntl_fork();

        if ($pid === 0) {
            LoopDriver::afterFork($childDriver);
            $fired = false;
            $childTimer = EventLoop::delay(0.05, function () use (&$fired) {
                $fired = true;
            });
            EventLoop::cancel($parentTimer); // what the parent-built object's close() or destructor does
            $enable = null;
            try {
                EventLoop::enable($parentTimer);
            } catch (Throwable $exception) {
                $enable = $exception::class;
            }
            EventLoop::run();

            fwrite($reportPeer, json_encode([
                'distinct' => $childTimer !== $parentTimer,
                'fired' => $fired,
                'enable' => $enable,
            ])."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo fgets($report);
        PHP, ['parentDriver' => $parentDriver, 'childDriver' => $childDriver]);

    expect($result)->toBe([
        'distinct' => true,
        'fired' => true,
        'enable' => Revolt\EventLoop\InvalidCallbackError::class,
    ]);
})->with([
    'poll to poll' => ['poll', 'poll'],
    'poll to select' => ['poll', 'select'],
    'select to poll' => ['select', 'poll'],
])->skip($requiresFork, 'needs pcntl and posix');

it('refuses an idle wait on the inherited PollDriver while the parent adds ready watchers', function () {
    // Without the pre-wait check the child segfaulted in about two of three forks on Linux (shared epoll, events
    // whose data lives in the parent's memory), so the scenario runs five rounds.
    $result = loopDriverForkScenario(<<<'PHP'
        use Revolt\EventLoop;

        Fledge\Async\EventLoop\LoopDriver::install('poll');
        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        EventLoop::unreference(EventLoop::onReadable($watched, static fn () => null));
        Fledge\Async\delay(0.01);
        $rounds = [];
        $pairs = [];
        $parentGot = 0;

        for ($round = 0; $round < 5; $round++) {
            [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();

            if ($pid === 0) {
                // No afterFork() and nothing to sync: the delay goes straight to a wait on the inherited Context.
                try {
                    Fledge\Async\delay(0.3);
                    $out = ['error' => null];
                } catch (Throwable $exception) {
                    $out = ['error' => $exception::class, 'message' => $exception->getMessage()];
                }

                fwrite($reportPeer, json_encode($out)."\n");
                exit(0);
            }

            usleep(50_000);
            for ($i = 0; $i < 20; $i++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $pairs[] = $pair;
                $stream = $pair[0];
                EventLoop::unreference(EventLoop::onReadable($stream, function () use ($stream, &$parentGot) {
                    $parentGot += strlen(fread($stream, 10));
                }));
                fwrite($pair[1], 'x');
            }
            Fledge\Async\delay(0.4);
            pcntl_waitpid($pid, $status);

            $rounds[] = [
                'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
                'child' => json_decode((string) fgets($report), true),
            ];
        }

        echo json_encode(['rounds' => $rounds, 'parentGot' => $parentGot]);
        PHP);

    expect($result['parentGot'])->toBe(100);

    foreach ($result['rounds'] as $round) {
        expect($round['childExit'])->toBe(0)
            ->and($round['child']['error'] ?? null)->toBe(LogicException::class)
            ->and($round['child']['message'])->toContain('LoopDriver::afterFork()');
    }
})->skip($requiresFork, 'needs pcntl and posix');

it('reserves parent callback ids through the public API when the counter cannot be copied', function (string $kind) {
    // The reflection fallback of afterFork(): exercised directly, since the installed Revolt always has $nextId.
    $parent = LoopDriver::create('poll');
    for ($i = 0; $i < 1000; $i++) {
        $parent->cancel($parent->defer(static fn () => null));
    }
    $parentNext = $parent->defer(static fn () => null);
    $parent->cancel($parentNext);

    $child = LoopDriver::create($kind);
    $reserved = (new ReflectionMethod(LoopDriver::class, 'reserveCallbackIds'))->invoke(null, $child, $parentNext);
    $childNext = $child->defer(static fn () => null);
    $child->cancel($childNext);

    expect($reserved)->toBe(1001)
        ->and(strlen($childNext) > strlen($parentNext) || ($childNext > $parentNext && strlen($childNext) === strlen($parentNext)))
        ->toBeTrue();
})->with(['poll', 'select']);
