<?php

namespace Fledge\Fiber\Http;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Pusher\Pusher;

class FiberHttpServiceProvider extends ServiceProvider
{
    /** Open connections per host for broadcast (Pusher/Reverb) clients. */
    private const BROADCAST_PER_HOST = 8;

    public function boot(): void
    {
        // Disabled under PHPUnit so application test suites keep Guzzle's
        // default handler and HTTP fakes. The handler's own behavior is
        // covered by the parity suite in tests/Fledge/http.
        if (! $this->app->runningUnitTests() && ! \defined('PHPUNIT_COMPOSER_INSTALL')) {
            Factory::globalHandler(new FledgeHandler);

            $this->registerBroadcasting();
            $this->registerS3();
        }
    }

    /**
     * Run the Pusher and Reverb broadcast drivers on the Fledge handler.
     *
     * Both drivers are built by BroadcastManager::pusher(), which hands
     * `client_options` to a plain Guzzle client, so a `handler` in there is
     * all it takes.
     *
     * Extend-order caveat: afterResolving runs right after the manager is
     * built, before application code can reach it, so an app's own
     * Broadcast::extend('pusher'|'reverb') in a provider boot() replaces
     * this one (last extend wins). To keep stock behavior for one
     * connection, set `client_options.handler` in config/broadcasting.php.
     */
    private function registerBroadcasting(): void
    {
        if (! config('fledge-http.integrations.broadcasting', true)
            || ! class_exists(Pusher::class)
            || ! class_exists(PusherBroadcaster::class)) {
            return;
        }

        $this->app->afterResolving(BroadcastingFactory::class, function ($manager): void {
            if (! method_exists($manager, 'extend') || ! method_exists($manager, 'pusher')) {
                return;
            }

            $driver = fn ($app, array $config) => new PusherBroadcaster(
                $manager->pusher(self::broadcastConfig($config)),
                $config['jsonp'] ?? false,
            );

            $manager->extend('pusher', $driver);
            $manager->extend('reverb', $driver);
        });
    }

    /**
     * Run the S3 filesystem driver on the Fledge handler through the AWS
     * SDK's Guzzle bridge. No-op while aws/aws-sdk-php is not installed.
     * Same extend-order caveat as registerBroadcasting().
     */
    private function registerS3(): void
    {
        if (! config('fledge-http.integrations.s3', true) || ! class_exists(S3Client::class)) {
            return;
        }

        $this->app->afterResolving('filesystem', function ($manager): void {
            if (! $manager instanceof FilesystemManager) {
                return;
            }

            $manager->extend('s3', fn ($app, array $config) => $manager->createS3Driver(self::s3Config($config)));
        });
    }

    /**
     * Add the Fledge Guzzle stack to a broadcast connection config unless
     * the app already configured a handler.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function broadcastConfig(array $config): array
    {
        if (isset($config['client_options']['handler'])) {
            return $config;
        }

        $config['client_options']['handler'] = FledgeGuzzle::stack(self::BROADCAST_PER_HOST);

        return $config;
    }

    /**
     * Add the Fledge-backed AWS HTTP handler to an S3 disk config unless
     * the app already configured an `http_handler`.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function s3Config(array $config): array
    {
        return $config + ['http_handler' => new GuzzleHandler(FledgeGuzzle::client())];
    }
}
