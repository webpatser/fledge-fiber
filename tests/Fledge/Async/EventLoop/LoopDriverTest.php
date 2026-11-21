<?php

declare(strict_types=1);

use Fledge\Async\EventLoop\Driver\PollDriver;
use Fledge\Async\EventLoop\LoopDriver;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\DriverFactory;

/*
 * resolve() and create() never touch the process-wide driver, so those tests run in this process. Every test that
 * calls install() runs in a fresh PHP process instead: once any earlier test in the suite has awaited from {main},
 * Revolt keeps its loop fiber suspended and reports the global driver as running until EventLoop::run() returns,
 * so the suite cannot promise an idle loop here (and Revolt's own setDriver() refuses to swap it).
 *
 * Each test restores the FLEDGE_EVENT_LOOP environment variable afterwards.
 */
beforeEach(function () {
    $this->previousEnv = getenv(LoopDriver::ENV);
    putenv(LoopDriver::ENV);
});

afterEach(function () {
    $this->previousEnv === false
        ? putenv(LoopDriver::ENV)
        : putenv(LoopDriver::ENV.'='.$this->previousEnv);
});

/**
 * Run PHP code in a fresh process (autoloaded, FLEDGE_EVENT_LOOP unset) and decode the JSON it prints.
 *
 * @param  array<string, mixed>  $vars  Variables defined (via var_export) before the code runs.
 * @return array<string, mixed>
 */
function loopDriverInFreshProcess(string $code, array $vars = []): array
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
    proc_close($process);

    $decoded = json_decode($stdout, true);

    if (! is_array($decoded)) {
        test()->fail("subprocess did not print JSON. stdout: {$stdout} stderr: {$stderr}");
    }

    return $decoded;
}

$revoltClass = (new DriverFactory())->create()::class;
$autoName = PollDriver::isSupported() ? 'poll' : 'revolt';
$autoClass = PollDriver::isSupported() ? PollDriver::class : $revoltClass;

dataset('names', [
    'poll' => ['poll', 'poll', PollDriver::class],
    'POLL padded' => ["  POLL\n", 'poll', PollDriver::class],
    'select' => ['select', 'select', StreamSelectDriver::class],
    'Select tab' => ["\tSelect ", 'select', StreamSelectDriver::class],
    'revolt' => ['revolt', 'revolt', $revoltClass],
    'ReVoLt' => [' ReVoLt', 'revolt', $revoltClass],
    'auto' => ['auto', $autoName, $autoClass],
    'AUTO padded' => [' AUTO ', $autoName, $autoClass],
    'empty means auto' => ['  ', $autoName, $autoClass],
]);

it('resolves and creates every accepted value', function (string $name, string $resolved, string $class) {
    expect(LoopDriver::resolve($name))->toBe($resolved);

    $driver = LoopDriver::create($name);

    expect($driver::class)->toBe($class)
        ->and(EventLoop::getDriver())->not->toBe($driver);
})->with('names');

it('creates exactly a StreamSelectDriver for the select rollback', function () {
    expect(LoopDriver::create('select'))->toBeInstanceOf(StreamSelectDriver::class)
        ->and(LoopDriver::create('select')::class)->toBe(StreamSelectDriver::class);
});

it('rejects an unknown name and lists the accepted values', function () {
    LoopDriver::create('epoll');
})->throws(InvalidArgumentException::class, 'expected one of: auto, poll, select, revolt');

it('rejects an unknown name from the environment', function () {
    putenv(LoopDriver::ENV.'=libuv');

    LoopDriver::resolve();
})->throws(InvalidArgumentException::class, 'libuv');

it('resolves auto to poll where the backend exists, and resolve and create agree', function () {
    expect(PollDriver::isSupported())->toBeTrue()
        ->and(LoopDriver::resolve('auto'))->toBe('poll')
        ->and(LoopDriver::create('auto'))->toBeInstanceOf(PollDriver::class);
});

it('defaults to auto when the environment variable is unset', function () {
    expect(LoopDriver::resolve())->toBe(LoopDriver::resolve('auto'));
});

it('reads the environment variable when no name is given', function () {
    putenv(LoopDriver::ENV.'= Select ');

    expect(LoopDriver::resolve())->toBe('select')
        ->and(LoopDriver::create()::class)->toBe(StreamSelectDriver::class);
});

it('installs the driver named by the environment variable when no name is given', function () {
    $result = loopDriverInFreshProcess(<<<'PHP'
        putenv(Fledge\Async\EventLoop\LoopDriver::ENV.'=poll');
        $driver = Fledge\Async\EventLoop\LoopDriver::install();
        echo json_encode([
            'class' => $driver::class,
            'installed' => Revolt\EventLoop::getDriver() === $driver,
        ]);
        PHP);

    expect($result)->toBe(['class' => PollDriver::class, 'installed' => true]);
});

it('prefers an explicit name over the environment variable', function () {
    putenv(LoopDriver::ENV.'=poll');

    expect(LoopDriver::resolve('select'))->toBe('select');
});

it('installs on an idle empty loop and runs a deferred callback and a timer', function (string $name, string $class) {
    $result = loopDriverInFreshProcess(<<<'PHP'
        $driver = Fledge\Async\EventLoop\LoopDriver::install($name);
        $installed = Revolt\EventLoop::getDriver() === $driver;

        $order = [];
        Revolt\EventLoop::delay(0.01, function () use (&$order) {
            $order[] = 'timer';
        });
        Revolt\EventLoop::defer(function () use (&$order) {
            $order[] = 'defer';
        });

        Revolt\EventLoop::run();

        echo json_encode(['class' => $driver::class, 'installed' => $installed, 'order' => $order]);
        PHP, ['name' => $name]);

    expect($result)->toBe(['class' => $class, 'installed' => true, 'order' => ['defer', 'timer']]);
})->with([
    'poll' => ['poll', PollDriver::class],
    'select' => ['select', StreamSelectDriver::class],
    'revolt' => ['revolt', $revoltClass],
]);

it('always installs a fresh driver, even of the same kind', function () {
    $result = loopDriverInFreshProcess(<<<'PHP'
        $first = Fledge\Async\EventLoop\LoopDriver::install('poll');
        $second = Fledge\Async\EventLoop\LoopDriver::install('poll');
        echo json_encode([
            'class' => $second::class,
            'fresh' => $second !== $first,
            'installed' => Revolt\EventLoop::getDriver() === $second,
        ]);
        PHP);

    expect($result)->toBe(['class' => PollDriver::class, 'fresh' => true, 'installed' => true]);
});

it('refuses to install while the loop is running', function () {
    $result = loopDriverInFreshProcess(<<<'PHP'
        $before = Revolt\EventLoop::getDriver();
        $caught = null;

        Revolt\EventLoop::defer(function () use (&$caught) {
            try {
                Fledge\Async\EventLoop\LoopDriver::install('select');
            } catch (Throwable $exception) {
                $caught = $exception;
            }
        });

        Revolt\EventLoop::run();

        echo json_encode([
            'class' => $caught === null ? null : $caught::class,
            'message' => $caught?->getMessage(),
            'unchanged' => Revolt\EventLoop::getDriver() === $before,
        ]);
        PHP);

    expect($result['class'])->toBe(LogicException::class)
        ->and($result['message'])->toContain('while the event loop is running')
        ->and($result['unchanged'])->toBeTrue();
});

it('refuses to install after {main} awaited, until the loop has run to completion', function () {
    $result = loopDriverInFreshProcess(<<<'PHP'
        $before = Revolt\EventLoop::getDriver();
        $suspension = Revolt\EventLoop::getSuspension();
        Revolt\EventLoop::defer(fn () => $suspension->resume());
        $suspension->suspend();

        $message = null;
        try {
            Fledge\Async\EventLoop\LoopDriver::install('select');
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }
        $unchanged = Revolt\EventLoop::getDriver() === $before;

        Revolt\EventLoop::run();
        $after = Fledge\Async\EventLoop\LoopDriver::install('select');

        echo json_encode(['message' => $message, 'unchanged' => $unchanged, 'after' => $after::class]);
        PHP);

    expect($result['message'])->toContain('while the event loop is running')
        ->and($result['unchanged'])->toBeTrue()
        ->and($result['after'])->toBe(StreamSelectDriver::class);
});

it('refuses to install while callbacks are registered', function (string $case) {
    $result = loopDriverInFreshProcess(<<<'PHP'
        $before = Revolt\EventLoop::getDriver();
        $id = match ($case) {
            'enabled defer' => Revolt\EventLoop::defer(static fn () => null),
            'disabled timer' => Revolt\EventLoop::disable(Revolt\EventLoop::delay(10, static fn () => null)),
            'unreferenced repeat' => Revolt\EventLoop::unreference(Revolt\EventLoop::repeat(10, static fn () => null)),
            'unreferenced readable' => Revolt\EventLoop::unreference(
                Revolt\EventLoop::onReadable(fopen('php://memory', 'r'), static fn () => null),
            ),
        };

        $caught = null;
        try {
            Fledge\Async\EventLoop\LoopDriver::install('select');
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $unchanged = Revolt\EventLoop::getDriver() === $before;

        Revolt\EventLoop::cancel($id);
        $after = Fledge\Async\EventLoop\LoopDriver::install('select');

        echo json_encode([
            'class' => $caught === null ? null : $caught::class,
            'message' => $caught?->getMessage(),
            'unchanged' => $unchanged,
            'replaced' => $after !== $before && Revolt\EventLoop::getDriver() === $after,
        ]);
        PHP, ['case' => $case]);

    expect($result['class'])->toBe(LogicException::class)
        ->and($result['message'])->toContain('1 callback(s) registered')
        ->and($result['unchanged'])->toBeTrue()
        ->and($result['replaced'])->toBeTrue();
})->with(['enabled defer', 'disabled timer', 'unreferenced repeat', 'unreferenced readable']);

it('leaves the active driver untouched when creation fails', function () {
    $before = EventLoop::getDriver();

    expect(fn () => LoopDriver::install('kqueue'))->toThrow(InvalidArgumentException::class)
        ->and(EventLoop::getDriver())->toBe($before);
});
