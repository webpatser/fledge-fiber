<?php

namespace Fledge\Fiber\Redis;

use Illuminate\Support\ServiceProvider;

class FiberRedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('redis.connector.fledge', fn () => new FledgeRedisConnector);
    }
}
