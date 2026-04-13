<?php

namespace Fledge\Fiber\Livewire;

use Fledge\Fiber\Livewire\Mechanisms\FiberHandleRequests;
use Illuminate\Support\ServiceProvider;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class FiberLivewireServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * Must run in register() (not boot()) so the binding is in place
     * before LivewireServiceProvider::boot() instantiates mechanisms.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fledge-livewire.php', 'fledge-livewire');

        $this->app->singleton(HandleRequests::class, FiberHandleRequests::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/fledge-livewire.php' => config_path('fledge-livewire.php'),
            ], 'fledge-livewire-config');
        }
    }
}
