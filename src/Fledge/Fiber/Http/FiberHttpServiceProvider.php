<?php

namespace Fledge\Fiber\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class FiberHttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fledge-http.php', 'fledge-http');

        $this->registerMailIntegration();
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
     * Same guard as boot(): off under PHPUnit so application suites keep
     * the stock managers and fakes.
     */
    protected function integrationsActive(): bool
    {
        return ! $this->app->runningUnitTests() && ! \defined('PHPUNIT_COMPOSER_INSTALL');
    }
}
