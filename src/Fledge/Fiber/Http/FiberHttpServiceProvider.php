<?php

namespace Fledge\Fiber\Http;

use Fledge\Fiber\Search\ElasticClientFactory;
use Fledge\Fiber\Search\FledgeElasticConnection;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use PDPhilip\Elasticsearch\Connection;

class FiberHttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerElasticsearch();
    }

    public function boot(): void
    {
        // Disabled under PHPUnit so application test suites keep Guzzle's
        // default handler and HTTP fakes. The handler's own behavior is
        // covered by the parity suite in tests/Fledge/http.
        if (! $this->app->runningUnitTests() && ! \defined('PHPUNIT_COMPOSER_INSTALL')) {
            Factory::globalHandler(new FledgeHandler);
        }
    }

    /**
     * Replace PDPhilip's `elasticsearch` database driver with one that
     * transports through the Fledge handler. The flag is read when the
     * database manager resolves, so config merged by other register() calls
     * is already in place. A no-op without the pdphilip/elasticsearch package.
     */
    private function registerElasticsearch(): void
    {
        if (! class_exists(Connection::class)) {
            return;
        }

        $this->app->singleton(ElasticClientFactory::class, fn ($app) => new ElasticClientFactory(
            (int) $app['config']->get('fledge-http.elasticsearch.pool_per_host', ElasticClientFactory::DEFAULT_CONNECTIONS_PER_HOST),
        ));

        $this->app->resolving('db', function ($db, $app): void {
            if (! $app['config']->get('fledge-http.integrations.elasticsearch', true)) {
                return;
            }

            $db->extend('elasticsearch', fn (array $config, string $name) => new FledgeElasticConnection([...$config, 'name' => $name]));
        });
    }
}
