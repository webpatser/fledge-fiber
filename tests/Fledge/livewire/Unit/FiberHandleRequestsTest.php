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
