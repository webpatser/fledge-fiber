<?php

namespace Fledge\Fiber\Livewire\Concerns;

use Livewire\Attributes\Computed;
use ReflectionMethod;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/**
 * Preloads all #[Computed] properties concurrently in Fibers.
 *
 * Call $this->preloadComputed() in mount() or a lifecycle hook to
 * resolve all computed properties in parallel. Subsequent access
 * via $this->propertyName hits Livewire's computed cache.
 *
 * @example
 * use Fledge\Fiber\Livewire\Concerns\WithConcurrentComputed;
 *
 * class Dashboard extends Component
 * {
 *     use WithConcurrentComputed;
 *
 *     #[Computed]
 *     public function users() { return User::all(); }
 *
 *     #[Computed]
 *     public function orders() { return Order::today()->get(); }
 *
 *     public function mount()
 *     {
 *         $this->preloadComputed(); // users + orders resolve in parallel
 *     }
 * }
 */
trait WithConcurrentComputed
{
    /**
     * Preload all #[Computed] properties concurrently.
     *
     * Each computed method runs in its own Fiber. Results are stored
     * in Livewire's computed cache so subsequent $this->property
     * access doesn't trigger another query.
     */
    protected function preloadComputed(): void
    {
        $methods = $this->getComputedMethods();

        if (count($methods) <= 1) {
            // Single or no computed — just access normally to warm cache
            foreach ($methods as $method) {
                $this->{$method};
            }

            return;
        }

        $futures = [];

        foreach ($methods as $key => $method) {
            $futures[$key] = async(fn () => $this->{$method});
        }

        await($futures);
    }

    /**
     * Discover all methods with the #[Computed] attribute.
     */
    protected function getComputedMethods(): array
    {
        $methods = [];

        foreach ((new \ReflectionClass($this))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! empty($method->getAttributes(Computed::class))) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }
}
