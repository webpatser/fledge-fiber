<?php

namespace Fledge\Fiber\Http;

use Aws\S3\S3Client;
use Fledge\Fiber\Search\ElasticClientFactory;
use Fledge\Fiber\Search\FledgeElasticConnection;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use PDPhilip\Elasticsearch\Connection;
use Pusher\Pusher;

class FiberHttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fledge-http.php', 'fledge-http');

        $this->registerMailIntegration();
        $this->registerElasticsearch();

        if ($this->integrationsActive()) {
            $this->registerBroadcasting();
            $this->registerS3();
        }
    }

    public function boot(): void
    {
        // Disabled under PHPUnit so application test suites keep Guzzle's
        // default handler and HTTP fakes. The handler's own behavior is
        // covered by the parity suite in tests/Fledge/http.
        if ($this->integrationsActive()) {
            Factory::globalHandler(new FledgeHandler);
        }
    }

    /**
     * Swap the mail manager for one whose HTTP transports run on Fledge.
     */
    private function registerMailIntegration(): void
    {
        if (! class_exists(MailManager::class) || ! $this->integrationsActive()) {
            return;
        }

        $this->app->extend('mail.manager', function ($manager, $app) {
            if (! $app['config']->get('fledge-http.integrations.mail', true) || $manager::class !== MailManager::class) {
                return $manager;
            }

            return new FiberMailManager($app);
        });
    }

    /**
     * Run the Pusher and Reverb broadcast drivers on the Fledge handler.
     *
     * Both drivers are built by BroadcastManager::pusher(), which hands
     * `client_options` to a plain Guzzle client, so a `handler` in there is
     * all it takes.
     *
     * Registered with callAfterResolving() so a manager that was already
     * resolved is covered too. The flag is read when the manager resolves.
     *
     * Extend-order caveat: afterResolving runs right after the manager is
     * built, before application code can reach it, so an app's own
     * Broadcast::extend('pusher'|'reverb') in a provider boot() replaces
     * this one (last extend wins). To keep stock behavior for one
     * connection, set `client_options.handler` in config/broadcasting.php.
     */
    private function registerBroadcasting(): void
    {
        if (! class_exists(Pusher::class) || ! class_exists(PusherBroadcaster::class)) {
            return;
        }

        $this->callAfterResolving(BroadcastingFactory::class, function ($manager, $app): void {
            if (! $app['config']->get('fledge-http.integrations.broadcasting', true)
                || ! method_exists($manager, 'extend')
                || ! method_exists($manager, 'pusher')) {
                return;
            }

            // BroadcastManager::extend() rebinds the closure's scope to the
            // manager, where self:: would hit __call() -> driver() -> this
            // closure again. Capture the provider helpers as callables first.
            $perHost = self::perHost(...);
            $configure = self::broadcastConfig(...);

            $driver = static fn ($app, array $config) => new PusherBroadcaster(
                $manager->pusher($configure($config, $perHost($app, 'broadcasting', 8))),
                $config['jsonp'] ?? false,
            );

            $manager->extend('pusher', $driver);
            $manager->extend('reverb', $driver);
        });
    }

    /**
     * Run the S3 filesystem driver on the Fledge handler through the AWS
     * SDK's Guzzle bridge. No-op while aws/aws-sdk-php (or its Guzzle
     * handler) is not installed. Same registration and extend-order caveat
     * as registerBroadcasting().
     */
    private function registerS3(): void
    {
        if (! class_exists(S3Client::class) || self::awsGuzzleHandlerClass() === null) {
            return;
        }

        $this->callAfterResolving('filesystem', function ($manager, $app): void {
            if (! $app['config']->get('fledge-http.integrations.s3', true) || ! $manager instanceof FilesystemManager) {
                return;
            }

            // FilesystemManager::extend() rebinds the closure's scope to the
            // manager too, so no self:: inside it (see registerBroadcasting()).
            $perHost = self::perHost(...);
            $configure = self::s3Config(...);

            $manager->extend('s3', static fn ($app, array $config) => $manager->createS3Driver(
                $configure($config, $perHost($app, 's3')),
            ));
        });
    }

    /**
     * The AWS SDK's Guzzle bridge class: Aws\Handler\Guzzle\GuzzleHandler on
     * current SDKs, Aws\Handler\GuzzleV6\GuzzleHandler on older ones, null
     * when neither exists.
     *
     * @return class-string|null
     */
    public static function awsGuzzleHandlerClass(): ?string
    {
        foreach (['Aws\\Handler\\Guzzle\\GuzzleHandler', 'Aws\\Handler\\GuzzleV6\\GuzzleHandler'] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * An AWS SDK http_handler on the Fledge stack, or null without the SDK.
     *
     * @param  int|null  $perHost  Maximum open connections per authority, null for no limit.
     */
    public static function awsHttpHandler(?int $perHost = null): ?object
    {
        $class = self::awsGuzzleHandlerClass();

        return $class === null ? null : new $class(FledgeGuzzle::client([], $perHost));
    }

    /**
     * The `fledge-http.pool_per_host.<integration>` limit. A missing key
     * falls back to $default, an explicit null means unlimited.
     *
     * @param  Container|\ArrayAccess<string, mixed>  $app
     */
    public static function perHost(mixed $app, string $integration, ?int $default = null): ?int
    {
        $pools = $app['config']->get('fledge-http.pool_per_host');

        if (! \is_array($pools) || ! \array_key_exists($integration, $pools)) {
            return $default;
        }

        $limit = $pools[$integration];

        return $limit === null || (int) $limit <= 0 ? null : (int) $limit;
    }

    /**
     * Same guard for every integration: off under PHPUnit so application
     * suites keep the stock managers and fakes.
     */
    protected function integrationsActive(): bool
    {
        return ! $this->app->runningUnitTests() && ! \defined('PHPUNIT_COMPOSER_INSTALL');
    }

    /**
     * Add the Fledge Guzzle stack to a broadcast connection config unless
     * the app already configured a handler.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function broadcastConfig(array $config, ?int $perHost = 8): array
    {
        if (isset($config['client_options']['handler'])) {
            return $config;
        }

        $config['client_options']['handler'] = FledgeGuzzle::stack($perHost);

        return $config;
    }

    /**
     * Add the Fledge-backed AWS HTTP handler to an S3 disk config unless
     * the app already configured an `http_handler`.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function s3Config(array $config, ?int $perHost = null): array
    {
        if (isset($config['http_handler'])) {
            return $config;
        }

        $handler = self::awsHttpHandler($perHost);

        return $handler === null ? $config : $config + ['http_handler' => $handler];
    }

    /**
     * Replace PDPhilip's `elasticsearch` database driver with one that
     * transports through the Fledge handler. The flag is read when the
     * database manager resolves, so config merged by other register() calls
     * is already in place. A no-op without the pdphilip/elasticsearch package
     * or while integrations are inactive (PHPUnit).
     *
     * PDPhilip's own provider extends `db` in a resolving() callback. The
     * container fires every resolving() callback before any afterResolving()
     * one, so registering here with afterResolving makes the Fledge driver win
     * regardless of provider order.
     */
    private function registerElasticsearch(): void
    {
        if (! class_exists(Connection::class) || ! $this->integrationsActive()) {
            return;
        }

        $this->app->singleton(ElasticClientFactory::class, fn ($app) => new ElasticClientFactory(
            self::perHost($app, 'elasticsearch', ElasticClientFactory::DEFAULT_CONNECTIONS_PER_HOST),
        ));

        $this->callAfterResolving('db', function ($db, $app): void {
            if (! $app['config']->get('fledge-http.integrations.elasticsearch', true)) {
                return;
            }

            $db->extend('elasticsearch', static fn (array $config, string $name) => new FledgeElasticConnection([...$config, 'name' => $name]));
        });
    }
}
