<?php

declare(strict_types=1);

use Fledge\Async\FiberContext;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * FiberContext lets a hook carry per-fiber state (e.g. the active tracing span) from the fiber calling async()
 * into the child fiber. The store below keeps one value per fiber, keyed like UsesNativePdoPool, plus a slot
 * for the main context (no fiber), and registers itself as a hook whose cleanup restores the previous value.
 */
beforeEach(function () {
    $this->store = new class
    {
        private WeakMap $values;

        private mixed $main = null;

        public function __construct()
        {
            $this->values = new WeakMap;
        }

        public function get(): mixed
        {
            $fiber = Fiber::getCurrent();

            return $fiber === null ? $this->main : ($this->values[$fiber] ?? null);
        }

        public function set(mixed $value): void
        {
            $fiber = Fiber::getCurrent();

            if ($fiber === null) {
                $this->main = $value;
            } else {
                $this->values[$fiber] = $value;
            }
        }

        public function register(string $name = 'test', ?array &$events = null): void
        {
            FiberContext::register(
                $name,
                fn (): mixed => $this->get(),
                function (mixed $snapshot) use ($name, &$events): Closure {
                    $events[] = "restore:{$name}";
                    $previous = $this->get();
                    $this->set($snapshot);

                    return function () use ($name, $previous, &$events): void {
                        $events[] = "cleanup:{$name}";
                        $this->set($previous);
                    };
                },
            );
        }
    };

    $this->previousErrorHandler = EventLoop::getErrorHandler();
});

afterEach(function () {
    foreach (['test', 'a', 'b', 'boom'] as $name) {
        FiberContext::forget($name);
    }

    EventLoop::setErrorHandler($this->previousErrorHandler);
});

it('captures nothing when no hook is registered', function () {
    expect(FiberContext::capture())->toBeNull();
});

it('restores the parent snapshot in the child fiber', function () {
    $this->store->register();
    $this->store->set('root');

    $future = async(fn (): mixed => $this->store->get());

    // The snapshot is taken when async() is called, not when the child starts.
    $this->store->set('changed');

    expect($future->await())->toBe('root');
});

it('runs the cleanup after the child finishes', function () {
    $events = [];
    $this->store->register('test', $events);

    async(function () use (&$events): void {
        $events[] = 'body';
    })->await();

    expect($events)->toBe(['restore:test', 'body', 'cleanup:test']);
});

it('runs the cleanup when the child throws', function () {
    $events = [];
    $this->store->register('test', $events);

    $future = async(function () use (&$events): never {
        $events[] = 'body';

        throw new RuntimeException('child failed');
    });

    expect(fn () => $future->await())->toThrow(RuntimeException::class, 'child failed')
        ->and($events)->toBe(['restore:test', 'body', 'cleanup:test']);
});

it('runs cleanups in reverse restore order', function () {
    $events = [];
    $this->store->register('a', $events);
    $this->store->register('b', $events);

    async(fn () => null)->await();

    expect($events)->toBe(['restore:a', 'restore:b', 'cleanup:b', 'cleanup:a']);
});

it('lets a nested async inherit the restored state of its parent', function () {
    $this->store->register();
    $this->store->set('root');

    $result = async(function (): array {
        $inherited = $this->store->get();
        $this->store->set('outer');

        $inner = async(fn (): mixed => $this->store->get())->await();

        return [$inherited, $inner, $this->store->get()];
    })->await();

    expect($result)->toBe(['root', 'outer', 'outer'])
        ->and($this->store->get())->toBe('root');
});

it('keeps concurrent siblings isolated from each other', function () {
    $this->store->register();
    $this->store->set('root');

    $sibling = function (string $value): array {
        $inherited = $this->store->get();
        $this->store->set($value);
        delay(0.01);

        return [$inherited, $this->store->get()];
    };

    $a = async($sibling, 'a');
    $b = async($sibling, 'b');

    expect($a->await())->toBe(['root', 'a'])
        ->and($b->await())->toBe(['root', 'b']);
});

it('stops propagating once a hook is forgotten', function () {
    $this->store->register();
    $this->store->set('root');

    FiberContext::forget('test');

    expect(FiberContext::capture())->toBeNull()
        ->and(async(fn (): mixed => $this->store->get())->await())->toBeNull();
});

it('skips a hook forgotten between capture and restore', function () {
    $this->store->register();
    $this->store->set('root');

    $future = async(fn (): mixed => $this->store->get());

    FiberContext::forget('test');

    expect($future->await())->toBeNull();
});

it('does not break async when a hook throws', function (string $stage) {
    $reported = [];
    EventLoop::setErrorHandler(function (Throwable $exception) use (&$reported): void {
        $reported[] = $exception->getMessage();
    });

    $fail = static fn (string $at) => $at === $stage ? throw new LogicException("{$at} failed") : null;

    FiberContext::register(
        'boom',
        static function () use ($fail): string {
            $fail('capture');

            return 'snapshot';
        },
        static function (mixed $snapshot) use ($fail): Closure {
            $fail('restore');

            return static fn () => $fail('cleanup');
        },
    );

    expect(async(fn (): string => 'done')->await())->toBe('done')
        ->and($reported)->toBe(["{$stage} failed"]);
})->with(['capture', 'restore', 'cleanup']);

it('swallows a throwing hook when no error handler is set', function () {
    EventLoop::setErrorHandler(null);

    FiberContext::register(
        'boom',
        static fn (): never => throw new LogicException('capture failed'),
        static fn (mixed $snapshot): null => null,
    );

    expect(async(fn (): string => 'done')->await())->toBe('done');
});
