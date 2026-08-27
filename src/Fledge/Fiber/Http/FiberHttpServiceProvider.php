<?php

namespace Fledge\Fiber\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class FiberHttpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Disabled under PHPUnit so application test suites keep Guzzle's
        // default handler and HTTP fakes. The handler's own behavior is
        // covered by the parity suite in tests/Fledge/http.
        if (! $this->app->runningUnitTests() && ! \defined('PHPUNIT_COMPOSER_INSTALL')) {
            Factory::globalHandler(new FledgeHandler);
        }
    }
}
