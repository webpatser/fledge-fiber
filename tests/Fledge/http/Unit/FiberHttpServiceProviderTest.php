<?php

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use Fledge\Fiber\Http\FiberHttpServiceProvider;
use Fledge\Fiber\Http\FledgeGuzzle;
use Fledge\Fiber\Http\FledgeHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Filesystem\FilesystemManager;

// The library ships no config() helper (the host app provides it); stand in.
if (! function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $repository = Container::getInstance()->make('config');

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

/**
 * Invoke a private provider method on a provider over a fresh container whose
 * `config` is a plain array repository.
 */
function providerWith(array $flags): FiberHttpServiceProvider
{
    $app = new class extends Container
    {
        public array $flags = [];
    };

    $repository = new class($flags)
    {
        public function __construct(private array $flags) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->flags[$key] ?? $default;
        }

        public function all(): array
        {
            return $this->flags;
        }
    };

    $app->instance('config', $repository);
    Container::setInstance($app);

    return new FiberHttpServiceProvider($app);
}

function callProvider(object $target, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
}

function handlerOf(HandlerStack $stack): mixed
{
    return (new ReflectionProperty($stack, 'handler'))->getValue($stack);
}

afterEach(function () {
    FledgeGuzzle::flush();
    Container::setInstance(null);
});

it('puts a Fledge handler stack into the broadcast client options', function () {
    $config = callProvider(providerWith([]), 'broadcastConfig', ['key' => 'k', 'client_options' => ['timeout' => 5]]);

    expect($config['client_options']['timeout'])->toBe(5)
        ->and($config['client_options']['handler'])->toBeInstanceOf(HandlerStack::class)
        ->and(handlerOf($config['client_options']['handler']))->toBeInstanceOf(FledgeHandler::class);
});

it('respects an explicit broadcast handler', function () {
    $custom = HandlerStack::create(fn () => null);

    $config = callProvider(providerWith([]), 'broadcastConfig', ['client_options' => ['handler' => $custom]]);

    expect($config['client_options']['handler'])->toBe($custom);
});

it('registers pusher and reverb extensions only when broadcasting is on', function (bool $enabled, bool $expected) {
    if (! class_exists(Pusher\Pusher::class) || ! class_exists(PusherBroadcaster::class)) {
        $this->markTestSkipped('pusher/pusher-php-server and illuminate/broadcasting are not installed.');
    }

    $provider = providerWith(['fledge-http.integrations.broadcasting' => $enabled]);
    $app = Container::getInstance();

    $manager = new class
    {
        public array $extended = [];

        public function extend(string $driver, Closure $callback): void
        {
            $this->extended[$driver] = $callback;
        }

        public function pusher(array $config): mixed
        {
            return $config;
        }
    };

    $app->bind(BroadcastingFactory::class, fn () => $manager);
    callProvider($provider, 'registerBroadcasting');
    $app->make(BroadcastingFactory::class);

    expect(array_keys($manager->extended))->toBe($expected ? ['pusher', 'reverb'] : []);
})->with([[true, true], [false, false]]);

it('builds a Pusher client on the Fledge handler through the broadcast manager', function () {
    if (! class_exists(Pusher\Pusher::class) || ! class_exists(BroadcastManager::class)) {
        $this->markTestSkipped('pusher/pusher-php-server and illuminate/broadcasting are not installed.');
    }

    $manager = new BroadcastManager(Container::getInstance());
    $config = callProvider(providerWith([]), 'broadcastConfig', ['key' => 'k', 'secret' => 's', 'app_id' => '1']);
    $pusher = $manager->pusher($config);

    $property = new ReflectionProperty($pusher, 'client');
    $client = $property->getValue($pusher);
    $stack = $client->getConfig('handler');

    expect(handlerOf($stack))->toBeInstanceOf(FledgeHandler::class);
});

it('injects the AWS Guzzle bridge into the S3 config and respects an explicit handler', function () {
    if (! class_exists(GuzzleHandler::class)) {
        $this->markTestSkipped('aws/aws-sdk-php is not installed.');
    }

    $provider = providerWith([]);
    $config = callProvider($provider, 's3Config', ['bucket' => 'b']);
    $explicit = fn () => null;

    expect($config['http_handler'])->toBeInstanceOf(GuzzleHandler::class)
        ->and(callProvider($provider, 's3Config', ['http_handler' => $explicit])['http_handler'])->toBe($explicit);
});

it('skips the S3 extension without the AWS SDK or with the flag off', function (bool $flag) {
    $provider = providerWith(['fledge-http.integrations.s3' => $flag]);
    $app = Container::getInstance();
    $manager = new FilesystemManager($app);
    $app->singleton('filesystem', fn () => $manager);

    callProvider($provider, 'registerS3');
    $app->make('filesystem');

    $creators = (new ReflectionProperty($manager, 'customCreators'))->getValue($manager);

    $registered = $flag && class_exists(S3Client::class);
    expect(isset($creators['s3']))->toBe($registered);
})->with([true, false]);

it('leaves the config repository free of objects and closures', function () {
    $provider = providerWith([
        'fledge-http.integrations.broadcasting' => true,
        'fledge-http.integrations.s3' => true,
    ]);

    callProvider($provider, 'registerBroadcasting');
    callProvider($provider, 'registerS3');
    callProvider($provider, 'broadcastConfig', ['client_options' => []]);

    $walk = function (mixed $value) use (&$walk): void {
        expect(is_object($value))->toBeFalse();
        if (is_array($value)) {
            array_walk($value, fn ($v) => $walk($v));
        }
    };
    $walk(config()->all());

    expect(fn () => var_export(config()->all(), true))->not->toThrow(Throwable::class);
});
