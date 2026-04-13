<?php

namespace Fledge\Fiber\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class FiberHttpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningUnitTests() && ! \defined('PHPUNIT_COMPOSER_INSTALL')) {
            Factory::globalHandler(new FledgeHandler);
        }
    }
}
