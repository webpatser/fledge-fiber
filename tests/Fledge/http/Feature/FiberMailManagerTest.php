<?php

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\Sdk;
use Fledge\Fiber\Http\FiberHttpServiceProvider;
use Fledge\Fiber\Http\FiberMailManager;
use Fledge\Fiber\Http\FledgeGuzzle;
use Fledge\Fiber\Http\Symfony\FledgeSymfonyHttpClient;
use Illuminate\Container\Container;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;

/**
 * Minimal config repository: dotted get() plus ArrayAccess over the same map,
 * which is all MailManager and the provider use.
 */
final class MailTestConfig implements ArrayAccess
{
    public function __construct(public array $items) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
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

final class MailTestApp extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }

    public function configurationIsCached(): bool
    {
        return false;
    }
}

/** Provider with the PHPUnit guard lifted, as in a real application. */
final class MailTestProvider extends FiberHttpServiceProvider
{
    public bool $active = true;

    protected function integrationsActive(): bool
    {
        return $this->active;
    }
}

function mailTestApp(bool $mailFlag = true): MailTestApp
{
    $app = new MailTestApp;
    $app->instance('config', new MailTestConfig([
        'fledge-http' => ['integrations' => ['mail' => $mailFlag]],
        'mail' => ['default' => 'postmark', 'mailers' => []],
    ]));
    $app->singleton('mail.manager', fn ($app) => new MailManager($app));

    return $app;
}

function mailHttpClient(object $manager, array $config): mixed
{
    return (new ReflectionMethod($manager, 'getHttpClient'))->invoke($manager, $config);
}

it('replaces the mail manager when the flag is on', function () {
    $app = mailTestApp();
    (new MailTestProvider($app))->register();

    expect($app->make('mail.manager'))->toBeInstanceOf(FiberMailManager::class);
});

it('keeps the stock mail manager when the flag is off', function () {
    $app = mailTestApp(mailFlag: false);
    (new MailTestProvider($app))->register();

    expect($app->make('mail.manager'))->not->toBeInstanceOf(FiberMailManager::class);
});

it('leaves the mail manager alone under the PHPUnit guard', function () {
    $app = mailTestApp();
    (new FiberHttpServiceProvider($app))->register();

    expect($app->make('mail.manager'))->not->toBeInstanceOf(FiberMailManager::class);

    $app = mailTestApp();
    $provider = new MailTestProvider($app);
    $provider->active = false;
    $provider->register();

    expect($app->make('mail.manager'))->not->toBeInstanceOf(FiberMailManager::class);
});

it('does not override a custom manager subclass', function () {
    $app = mailTestApp();
    $custom = new class($app) extends MailManager {};
    $app->singleton('mail.manager', fn () => $custom);
    (new MailTestProvider($app))->register();

    expect($app->make('mail.manager'))->toBe($custom);
});

it('ships config defaults that are cache safe scalars', function () {
    $config = require __DIR__.'/../../../../src/Fledge/Fiber/config/fledge-http.php';

    expect($config['integrations'])->toBe(['mail' => true, 'broadcasting' => true, 's3' => true, 'elasticsearch' => true])
        ->and($config['pool_per_host']['elasticsearch'])->toBe(8);
});

it('hands transports a Fledge client, even without client options', function () {
    $manager = new FiberMailManager(mailTestApp());

    expect(mailHttpClient($manager, ['transport' => 'postmark']))->toBeInstanceOf(FledgeSymfonyHttpClient::class)
        ->and(mailHttpClient($manager, ['client' => ['timeout' => 3]]))->toBeInstanceOf(FledgeSymfonyHttpClient::class);
});

it('lets a mailer opt out with client.fledge = false', function () {
    $manager = new FiberMailManager(mailTestApp());

    expect(mailHttpClient($manager, ['client' => ['fledge' => false]]))->not->toBeInstanceOf(FledgeSymfonyHttpClient::class)
        ->and(mailHttpClient($manager, ['client' => ['fledge' => false, 'timeout' => 3]]))->not->toBeInstanceOf(FledgeSymfonyHttpClient::class);
});

it('passes the remaining client options through as default options', function () {
    $manager = new FiberMailManager(mailTestApp());
    $client = mailHttpClient($manager, ['client' => ['fledge' => true, 'timeout' => 3.5]]);

    $options = (new ReflectionProperty($client, 'defaultOptions'))->getValue($client);

    expect($options['timeout'])->toBe(3.5)
        ->and($options)->not->toHaveKey('fledge');
});

it('injects a Fledge http_handler into the SES config when the AWS SDK exists', function () {
    $manager = new FiberMailManager(mailTestApp());
    $withHandler = fn (array $config) => (new ReflectionMethod($manager, 'withSesHandler'))->invoke($manager, $config);

    if (! class_exists(Sdk::class)) {
        expect($withHandler(['transport' => 'ses']))->not->toHaveKey('http_handler');

        return;
    }

    $custom = fn () => null;

    expect($withHandler(['transport' => 'ses'])['http_handler'])->toBeInstanceOf(GuzzleHandler::class)
        ->and($withHandler(['client' => ['fledge' => false]]))->not->toHaveKey('http_handler')
        ->and($withHandler(['http_handler' => $custom])['http_handler'])->toBe($custom);
});

it('maps max_host_connections to the per-host pool and drops max_pending_pushes', function () {
    $manager = new FiberMailManager(mailTestApp());
    $client = mailHttpClient($manager, ['client' => ['max_host_connections' => 3, 'max_pending_pushes' => 10, 'timeout' => 2]]);

    $options = (new ReflectionProperty($client, 'defaultOptions'))->getValue($client);
    $factory = (new ReflectionProperty($client, 'factory'))->getValue($client);

    expect($client)->toBeInstanceOf(FledgeSymfonyHttpClient::class)
        ->and($options)->not->toHaveKey('max_host_connections')
        ->and($options)->not->toHaveKey('max_pending_pushes')
        ->and($factory)->toBe(FledgeGuzzle::factory(3));
});

it('uses fledge-http.pool_per_host.mail when the mailer sets no limit', function () {
    $app = mailTestApp();
    $app['config']->set('fledge-http.pool_per_host.mail', 5);
    $client = mailHttpClient(new FiberMailManager($app), ['transport' => 'postmark']);

    expect((new ReflectionProperty($client, 'factory'))->getValue($client))->toBe(FledgeGuzzle::factory(5));
});

it('leaves an http_handler from services.ses alone', function () {
    $app = mailTestApp();
    $custom = fn () => null;
    $app['config']->set('services.ses.http_handler', $custom);
    $manager = new FiberMailManager($app);

    $config = (new ReflectionMethod($manager, 'withSesHandler'))->invoke($manager, ['transport' => 'ses']);

    expect($config)->not->toHaveKey('http_handler');
});
