<?php

use Aws\S3\S3Client;
use Aws\Ses\SesClient;
use Fledge\Fiber\Http\FiberHttpServiceProvider;
use Fledge\Fiber\Http\FiberMailManager;
use Fledge\Fiber\Http\FledgeGuzzle;
use Fledge\Fiber\Http\FledgeHandler;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Arr;

/*
 * Resolve every driver the provider registers through the REAL Laravel
 * manager. BroadcastManager and FilesystemManager rebind extend() closures
 * to themselves, so a closure that leans on self::, static:: or $this would
 * silently change meaning (BroadcastManager::__call -> driver() -> the same
 * closure -> stack overflow). Calling the closures directly cannot catch that.
 */

final class ExtendResolutionConfig implements ArrayAccess
{
    public function __construct(public array $items) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->get($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        Arr::set($this->items, $offset, $value);
    }

    public function offsetUnset(mixed $offset): void {}
}

final class ExtendResolutionProvider extends FiberHttpServiceProvider
{
    protected function integrationsActive(): bool
    {
        return true;
    }
}

function extendResolutionApp(array $config): Container
{
    $app = new Container;
    $app->instance('config', new ExtendResolutionConfig($config));
    Container::setInstance($app);

    return $app;
}

function extendResolutionRegister(Container $app, string $method): void
{
    $provider = new ExtendResolutionProvider($app);
    (new ReflectionMethod(FiberHttpServiceProvider::class, $method))->invoke($provider);
}

afterEach(function () {
    FledgeGuzzle::flush();
    Container::setInstance(null);
});

it('resolves the pusher and reverb broadcasters through a real BroadcastManager', function (string $connection) {
    if (! class_exists(Pusher\Pusher::class) || ! class_exists(BroadcastManager::class)) {
        $this->markTestSkipped('pusher/pusher-php-server and illuminate/broadcasting are not installed.');
    }

    $app = extendResolutionApp(['broadcasting' => ['connections' => [
        $connection => [
            'driver' => $connection,
            'key' => 'key',
            'secret' => 'secret',
            'app_id' => '1',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
        ],
    ]]]);
    $app->singleton(BroadcastingFactory::class, fn ($app) => new BroadcastManager($app));

    extendResolutionRegister($app, 'registerBroadcasting');

    $broadcaster = $app->make(BroadcastingFactory::class)->connection($connection);
    $client = (new ReflectionProperty($broadcaster->getPusher(), 'client'))->getValue($broadcaster->getPusher());
    $stack = $client->getConfig('handler');

    expect($broadcaster)->toBeInstanceOf(PusherBroadcaster::class)
        ->and((new ReflectionProperty($stack, 'handler'))->getValue($stack))->toBeInstanceOf(FledgeHandler::class);
})->with(['pusher', 'reverb']);

it('resolves the s3 disk through a real FilesystemManager', function () {
    if (! class_exists(S3Client::class) || ! class_exists(AwsS3V3Adapter::class)
        || FiberHttpServiceProvider::awsGuzzleHandlerClass() === null) {
        $this->markTestSkipped('aws/aws-sdk-php or league/flysystem-aws-s3-v3 is not installed.');
    }

    $app = extendResolutionApp(['filesystems' => ['disks' => [
        's3' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1', 'bucket' => 'b'],
    ]]]);
    $app->singleton('filesystem', fn ($app) => new FilesystemManager($app));

    extendResolutionRegister($app, 'registerS3');

    $disk = $app->make('filesystem')->disk('s3');

    expect($disk)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($disk->getClient())->toBeInstanceOf(S3Client::class);
});

it('resolves an SES transport through the swapped mail manager', function () {
    if (! class_exists(MailManager::class) || ! class_exists(SesClient::class)) {
        $this->markTestSkipped('illuminate/mail or aws/aws-sdk-php is not installed.');
    }

    $app = extendResolutionApp([
        'fledge-http' => ['integrations' => ['mail' => true]],
        'mail' => ['default' => 'ses', 'mailers' => []],
    ]);
    $app->singleton('mail.manager', fn ($app) => new MailManager($app));

    extendResolutionRegister($app, 'registerMailIntegration');

    $manager = $app->make('mail.manager');
    $transport = $manager->createSymfonyTransport(['transport' => 'ses', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1']);

    expect($manager)->toBeInstanceOf(FiberMailManager::class)
        ->and($transport)->toBeInstanceOf(SesTransport::class);
});
