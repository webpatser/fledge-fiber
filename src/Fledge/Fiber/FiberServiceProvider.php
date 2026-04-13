<?php

namespace Fledge\Fiber;

use Fledge\Fiber\Database\FiberDatabaseServiceProvider;
use Fledge\Fiber\Http\FiberHttpServiceProvider;
use Fledge\Fiber\Livewire\FiberLivewireServiceProvider;
use Fledge\Fiber\Redis\FiberRedisServiceProvider;
use Illuminate\Support\ServiceProvider;

class FiberServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(FiberDatabaseServiceProvider::class);
        $this->app->register(FiberHttpServiceProvider::class);
        $this->app->register(FiberRedisServiceProvider::class);

        if (class_exists(\Livewire\LivewireServiceProvider::class)) {
            $this->app->register(FiberLivewireServiceProvider::class);
        }
    }
}
