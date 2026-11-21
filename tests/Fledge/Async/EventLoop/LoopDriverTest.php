<?php

declare(strict_types=1);

use Fledge\Async\EventLoop\Driver\PollDriver;
use Fledge\Async\EventLoop\LoopDriver;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\DriverFactory;

/*
 * Every test starts on a fresh, empty StreamSelectDriver and restores the suite's previous driver and the
 * FLEDGE_EVENT_LOOP environment variable afterwards.
 */
beforeEach(function () {
    $this->previousDriver = EventLoop::getDriver();
    $this->previousEnv = getenv(LoopDriver::ENV);
    putenv(LoopDriver::ENV);
    EventLoop::setDriver(new StreamSelectDriver());
});

afterEach(function () {
    EventLoop::setDriver($this->previousDriver);
    $this->previousEnv === false
        ? putenv(LoopDriver::ENV)
        : putenv(LoopDriver::ENV.'='.$this->previousEnv);
});

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

    putenv(LoopDriver::ENV.'=poll');

    expect(LoopDriver::install())->toBeInstanceOf(PollDriver::class)
        ->and(EventLoop::getDriver())->toBeInstanceOf(PollDriver::class);
});

it('prefers an explicit name over the environment variable', function () {
    putenv(LoopDriver::ENV.'=poll');

    expect(LoopDriver::resolve('select'))->toBe('select');
});

it('installs on an idle empty loop and runs a deferred callback and a timer', function (string $name) {
    $driver = LoopDriver::install($name);

    expect(EventLoop::getDriver())->toBe($driver);

    $order = [];
    EventLoop::delay(0.01, function () use (&$order) {
        $order[] = 'timer';
    });
    EventLoop::defer(function () use (&$order) {
        $order[] = 'defer';
    });

    EventLoop::run();

    expect($order)->toBe(['defer', 'timer']);
})->with(['poll', 'select', 'revolt']);

it('always installs a fresh driver, even of the same kind', function () {
    $first = LoopDriver::install('poll');
    $second = LoopDriver::install('poll');

    expect($second)->toBeInstanceOf(PollDriver::class)
        ->and($second)->not->toBe($first)
        ->and(EventLoop::getDriver())->toBe($second);
});

it('refuses to install while the loop is running', function () {
    $before = EventLoop::getDriver();
    $caught = null;

    EventLoop::defer(function () use (&$caught) {
        try {
            LoopDriver::install('select');
        } catch (Throwable $exception) {
            $caught = $exception;
        }
    });

    EventLoop::run();

    expect($caught)->toBeInstanceOf(LogicException::class)
        ->and($caught->getMessage())->toContain('while the event loop is running')
        ->and(EventLoop::getDriver())->toBe($before);
});

it('refuses to install while callbacks are registered', function (Closure $register) {
    $before = EventLoop::getDriver();
    $id = $register();

    try {
        expect(fn () => LoopDriver::install('select'))
            ->toThrow(LogicException::class, '1 callback(s) registered')
            ->and(EventLoop::getDriver())->toBe($before);
    } finally {
        EventLoop::cancel($id);
    }

    expect(LoopDriver::install('select'))->not->toBe($before);
})->with([
    'enabled defer' => fn () => EventLoop::defer(static fn () => null),
    'disabled timer' => fn () => EventLoop::disable(EventLoop::delay(10, static fn () => null)),
    'unreferenced repeat' => fn () => EventLoop::unreference(EventLoop::repeat(10, static fn () => null)),
    'unreferenced readable' => function () {
        $stream = fopen('php://memory', 'r');

        return EventLoop::unreference(EventLoop::onReadable($stream, static fn () => null));
    },
]);

it('leaves the active driver untouched when creation fails', function () {
    $before = EventLoop::getDriver();

    expect(fn () => LoopDriver::install('kqueue'))->toThrow(InvalidArgumentException::class)
        ->and(EventLoop::getDriver())->toBe($before);
});
