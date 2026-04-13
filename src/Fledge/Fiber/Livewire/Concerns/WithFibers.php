<?php

namespace Fledge\Fiber\Livewire\Concerns;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/**
 * Provides a concurrently() helper for running multiple operations
 * in parallel Fibers within a Livewire component.
 *
 * @example
 * use Fledge\Fiber\Livewire\Concerns\WithFibers;
 *
 * class Dashboard extends Component
 * {
 *     use WithFibers;
 *
 *     public function mount()
 *     {
 *         [$this->users, $this->orders, $this->revenue] = $this->concurrently(
 *             fn() => User::where('active', true)->count(),
 *             fn() => Order::whereDate('created_at', today())->get(),
 *             fn() => Payment::sum('amount'),
 *         );
 *     }
 * }
 */
trait WithFibers
{
    /**
     * Run multiple operations concurrently in Fibers.
     *
     * Returns results in the same order as the callbacks.
     *
     * @param  callable  ...$operations
     * @return array
     */
    protected function concurrently(callable ...$operations): array
    {
        if (count($operations) <= 1) {
            return array_map(fn (callable $op) => $op(), $operations);
        }

        $futures = array_map(fn (callable $op) => async($op), $operations);

        $results = await($futures);
        ksort($results);

        return array_values($results);
    }
}
