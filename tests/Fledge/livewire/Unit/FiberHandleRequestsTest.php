<?php

use Fledge\Fiber\Livewire\Mechanisms\FiberHandleRequests;

it('extends Livewire HandleRequests', function () {
    expect(FiberHandleRequests::class)
        ->toExtend(\Livewire\Mechanisms\HandleRequests\HandleRequests::class);
});

it('has processComponent method', function () {
    $ref = new ReflectionMethod(FiberHandleRequests::class, 'processComponent');

    expect($ref->getNumberOfParameters())->toBe(1)
        ->and($ref->isProtected())->toBeTrue();
});

it('has shouldUseFibers method', function () {
    $ref = new ReflectionMethod(FiberHandleRequests::class, 'shouldUseFibers');

    expect($ref->isProtected())->toBeTrue();
});

it('has processConcurrently method', function () {
    $ref = new ReflectionMethod(FiberHandleRequests::class, 'processConcurrently');

    expect($ref->getNumberOfParameters())->toBe(2)
        ->and($ref->isProtected())->toBeTrue();
});

/*
 * The library ships no foundation helpers (the host app provides them), so
 * stand in for app(), config(), report() and abort() over the container.
 */
if (! function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = Illuminate\Container\Container::getInstance();

        return $abstract === null ? $container : $container->make($abstract, $parameters);
    }
}

if (! function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $repository = Illuminate\Container\Container::getInstance()->make('config');

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

if (! function_exists('report')) {
    function report(Throwable $exception): void
    {
        Illuminate\Container\Container::getInstance()
            ->make(Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->report($exception);
    }
}

if (! function_exists('abort')) {
    function abort(int $code): never
    {
        throw new Symfony\Component\HttpKernel\Exception\HttpException($code);
    }
}

/**
 * Bind a fresh container with the given debug flag, a recording exception
 * handler and a Livewire manager whose update() throws a TypeError.
 */
function bindTypeErrorContainer(bool $debug): object
{
    $container = new Illuminate\Container\Container;

    $container->instance('config', new class(['app.debug' => $debug])
    {
        public function __construct(private array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->items;
        }
    });

    $handler = new class implements Illuminate\Contracts\Debug\ExceptionHandler
    {
        public array $reported = [];

        public function report(Throwable $e)
        {
            $this->reported[] = $e;
        }

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e) {}
    };

    $container->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

    $container->instance('livewire', new class
    {
        public function update(...$args): array
        {
            throw new TypeError('Argument #1 must be of type string, null given');
        }
    });

    Illuminate\Container\Container::setInstance($container);

    return $handler;
}

afterEach(fn () => Illuminate\Container\Container::setInstance(null));

function typeErrorPayload(): array
{
    return ['snapshot' => '{"memo":{"id":"a"}}', 'updates' => [], 'calls' => []];
}

it('reports a TypeError during the update before turning it into a 419', function () {
    $handler = bindTypeErrorContainer(debug: false);
    $method = new ReflectionMethod(FiberHandleRequests::class, 'processComponent');

    try {
        $method->invoke(new FiberHandleRequests, typeErrorPayload());
        $this->fail('Expected a 419 HttpException.');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(419);
    }

    expect($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0])->toBeInstanceOf(TypeError::class);
});

it('reports a TypeError during the update and rethrows it in debug mode', function () {
    $handler = bindTypeErrorContainer(debug: true);
    $method = new ReflectionMethod(FiberHandleRequests::class, 'processComponent');

    expect(fn () => $method->invoke(new FiberHandleRequests, typeErrorPayload()))
        ->toThrow(TypeError::class);

    expect($handler->reported)->toHaveCount(1);
});

it('ships with concurrent fibers disabled by default', function () {
    $shipped = require dirname(__DIR__, 4).'/src/Fledge/Fiber/config/fledge-livewire.php';

    $container = new Illuminate\Container\Container;
    $container->instance('config', new class(['fledge-livewire.concurrent' => $shipped['concurrent']])
    {
        public function __construct(private array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    });
    Illuminate\Container\Container::setInstance($container);

    $method = new ReflectionMethod(FiberHandleRequests::class, 'shouldUseFibers');

    expect($shipped['concurrent'])->toBeFalse()
        ->and($method->invoke(new FiberHandleRequests))->toBeFalse();
});

it('disables concurrent fibers when the config key is absent', function () {
    $container = new Illuminate\Container\Container;
    $container->instance('config', new class([])
    {
        public function __construct(private array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    });
    Illuminate\Container\Container::setInstance($container);

    $method = new ReflectionMethod(FiberHandleRequests::class, 'shouldUseFibers');

    expect($method->invoke(new FiberHandleRequests))->toBeFalse();
});

if (! function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = Illuminate\Container\Container::getInstance()->make('request');

        return $key === null ? $request : $request->input($key, $default);
    }
}

it('measures the body, not the Content-Length header, against livewire.payload.max_size', function (?string $contentLength) {
    $container = new Illuminate\Container\Container;
    $container->instance('config', new class(['fledge-livewire.concurrent' => true, 'livewire.payload.max_size' => 1024])
    {
        public function __construct(private array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    });

    $body = json_encode(['components' => [typeErrorPayload()], 'padding' => str_repeat('a', 4096)]);
    $request = Illuminate\Http\Request::create('/livewire/update', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    $request->headers->remove('Content-Length');

    if ($contentLength === null) {
        $request->headers->set('Transfer-Encoding', 'chunked');
    } else {
        $request->headers->set('Content-Length', $contentLength);
    }

    $container->instance('request', $request);
    Illuminate\Container\Container::setInstance($container);

    expect(fn () => (new FiberHandleRequests)->handleUpdate())
        ->toThrow(Livewire\Exceptions\PayloadTooLargeException::class);
})->with([
    'chunked body without Content-Length' => [null],
    'understated Content-Length' => ['10'],
]);

/**
 * Bind a container whose Livewire manager records every update() and, for
 * the parent, stores the child's params the way a parent render does
 * through the mount.stub hook.
 */
function bindReactiveContainer(array $childParams): object
{
    $container = new Illuminate\Container\Container;

    $container->instance('config', new class([])
    {
        public function __construct(private array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    });

    $manager = new class($childParams)
    {
        public array $updated = [];

        public function __construct(private array $childParams) {}

        public function update(array $snapshot, array $updates, array $calls): array
        {
            $this->updated[] = $snapshot['memo']['id'];

            if ($snapshot['memo']['id'] === 'parent') {
                Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingChildParams['child'] = $this->childParams;
            }

            return [$snapshot, ['html' => '<div></div>']];
        }
    };

    $container->instance('livewire', $manager);
    Illuminate\Container\Container::setInstance($container);

    return $manager;
}

function reactivePayload(string $id, array $data, array $props = [], array $updates = [], array $calls = [], array $bindings = []): array
{
    $memo = ['id' => $id];

    if ($props !== []) {
        $memo['props'] = $props;
    }

    if ($bindings !== []) {
        $memo['bindings'] = $bindings;
    }

    return [
        'snapshot' => json_encode(['data' => $data, 'memo' => $memo]),
        'updates' => $updates,
        'calls' => $calls,
    ];
}

/**
 * Run the parent, then the child, through processComponent as one request.
 */
function runParentAndChild(FiberHandleRequests $requests, array $childPayload): array
{
    $method = new ReflectionMethod($requests, 'processComponent');

    return [
        $method->invoke($requests, reactivePayload('parent', ['count' => 1], updates: ['count' => 1])),
        $method->invoke($requests, $childPayload),
    ];
}

afterEach(function () {
    Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingChildParams = [];
    Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingUpdates = [];
});

it('skips a reactive child whose props did not change, as stock Livewire does', function () {
    $manager = bindReactiveContainer(['count' => 1]);

    [$parent, $child] = runParentAndChild(new FiberHandleRequests, reactivePayload('child', ['count' => 1], props: ['count'], calls: [['method' => '$commit', 'params' => []]]));

    expect($parent)->toHaveKeys(['snapshot', 'effects'])
        ->and($child)->toBe(['skip' => true, 'id' => 'child'])
        ->and($manager->updated)->toBe(['parent']);
});

it('updates a reactive child when the conditions for skipping are not met', function (array $childParams, array $childPayload) {
    $manager = bindReactiveContainer($childParams);

    [, $child] = runParentAndChild(new FiberHandleRequests, $childPayload);

    expect($child)->toHaveKeys(['snapshot', 'effects'])
        ->and($manager->updated)->toBe(['parent', 'child']);
})->with([
    'prop changed' => [['count' => 2], reactivePayload('child', ['count' => 1], props: ['count'])],
    'child has updates' => [['count' => 1], reactivePayload('child', ['count' => 1], props: ['count'], updates: ['other' => 'x'])],
    'child has a real call' => [['count' => 1], reactivePayload('child', ['count' => 1], props: ['count'], calls: [['method' => 'save', 'params' => []]])],
    'child has wire:model bindings' => [['count' => 1], reactivePayload('child', ['count' => 1], props: ['count'], bindings: ['count'])],
    'child has no reactive props' => [['count' => 1], reactivePayload('child', ['count' => 1])],
]);

/**
 * Bind a container whose Livewire manager suspends every update() once, the
 * way a Fledge database query does, before a parent stores its child's
 * params. Each update records which pending params it saw.
 */
function bindSuspendingReactiveContainer(array $parents): object
{
    $container = new Illuminate\Container\Container;

    $container->instance('config', new class(['fledge-livewire.max_concurrent' => 10])
    {
        public function __construct(private array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    });

    $manager = new class($parents)
    {
        public array $updated = [];

        public array $seen = [];

        public function __construct(private array $parents) {}

        public function update(array $snapshot, array $updates, array $calls): array
        {
            $id = $snapshot['memo']['id'];
            $this->updated[] = $id;
            $this->seen[$id] = Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingChildParams[$id] ?? null;

            // Suspend the fiber as a query would under Fledge.
            Fledge\Async\delay(0);

            foreach ($this->parents[$id] ?? [] as $childId => $params) {
                Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingChildParams[$childId] = $params;
            }

            return [$snapshot, ['html' => '<div></div>']];
        }
    };

    $container->instance('livewire', $manager);
    Illuminate\Container\Container::setInstance($container);

    return $manager;
}

function runConcurrently(array $payloads): array
{
    $requests = new FiberHandleRequests;

    return (new ReflectionMethod($requests, 'processConcurrently'))
        ->invoke($requests, $payloads, fn ($payload) => $payload)['components'];
}

it('hydrates a reactive child after its suspending parent stored the new props', function () {
    $manager = bindSuspendingReactiveContainer(['parent' => ['child' => ['count' => 2]]]);

    $components = runConcurrently([
        reactivePayload('parent', ['count' => 2], updates: ['count' => 2]),
        reactivePayload('child', ['count' => 1], props: ['count'], calls: [['method' => '$commit', 'params' => []]]),
    ]);

    expect($manager->seen['child'])->toBe(['count' => 2])
        ->and(json_decode($components[0]['snapshot'], true)['memo']['id'])->toBe('parent')
        ->and(json_decode($components[1]['snapshot'], true)['memo']['id'])->toBe('child');
});

it('skips an unchanged reactive child after its suspending parent, as stock Livewire does', function () {
    $manager = bindSuspendingReactiveContainer(['parent' => ['child' => ['count' => 1]]]);

    $components = runConcurrently([
        reactivePayload('other', ['n' => 1], updates: ['n' => 1]),
        reactivePayload('child', ['count' => 1], props: ['count'], calls: [['method' => '$commit', 'params' => []]]),
        reactivePayload('parent', ['count' => 1], updates: ['count' => 1]),
    ]);

    // Stock processes in batch order: a child listed before its parent is
    // not skipped, so swap the order to prove the skip path.
    expect($components[1])->toHaveKeys(['snapshot', 'effects']);

    Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingChildParams = [];
    $manager = bindSuspendingReactiveContainer(['parent' => ['child' => ['count' => 1]]]);

    $components = runConcurrently([
        reactivePayload('other', ['n' => 1], updates: ['n' => 1]),
        reactivePayload('parent', ['count' => 1], updates: ['count' => 1]),
        reactivePayload('child', ['count' => 1], props: ['count'], calls: [['method' => '$commit', 'params' => []]]),
    ]);

    expect($components[2])->toBe(['skip' => true, 'id' => 'child'])
        ->and($manager->updated)->toEqualCanonicalizing(['other', 'parent'])
        ->and(json_decode($components[0]['snapshot'], true)['memo']['id'])->toBe('other')
        ->and(json_decode($components[1]['snapshot'], true)['memo']['id'])->toBe('parent');
});

it('runs nested reactive children in batch order after their reactive parent', function () {
    $manager = bindSuspendingReactiveContainer([
        'parent' => ['child' => ['count' => 2]],
        'child' => ['grandchild' => ['count' => 2]],
    ]);

    $components = runConcurrently([
        reactivePayload('parent', ['count' => 2], updates: ['count' => 2]),
        reactivePayload('child', ['count' => 1], props: ['count'], calls: [['method' => '$commit', 'params' => []]]),
        reactivePayload('grandchild', ['count' => 1], props: ['count'], calls: [['method' => '$commit', 'params' => []]]),
    ]);

    expect($manager->seen['child'])->toBe(['count' => 2])
        ->and($manager->seen['grandchild'])->toBe(['count' => 2])
        ->and(array_map(fn ($c) => json_decode($c['snapshot'], true)['memo']['id'], $components))
        ->toBe(['parent', 'child', 'grandchild']);
});

it('rethrows a TypeError while exceptions are propagated, as stock Livewire does', function () {
    $handler = bindTypeErrorContainer(debug: false);
    $requests = new FiberHandleRequests;

    expect(fn () => $requests->temporarilyPropagateExceptions(
        fn () => (new ReflectionMethod($requests, 'processComponent'))->invoke($requests, typeErrorPayload())
    ))->toThrow(TypeError::class);

    expect($handler->reported)->toHaveCount(1);
});

it('reports a TypeError from a concurrent component before turning it into a 419', function () {
    $handler = bindTypeErrorContainer(debug: false);

    $requests = new class extends FiberHandleRequests
    {
        protected function processComponent(array $componentPayload): array
        {
            throw new TypeError('Argument #1 must be of type string, null given');
        }
    };

    $method = new ReflectionMethod($requests, 'processConcurrently');

    try {
        $method->invoke($requests, [typeErrorPayload()], fn ($payload) => $payload);
        $this->fail('Expected a 419 HttpException.');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(419);
    }

    expect($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0])->toBeInstanceOf(TypeError::class);
});
