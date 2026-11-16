<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Concurrent Livewire Updates
    |--------------------------------------------------------------------------
    |
    | When enabled, multiple Livewire component updates within a single
    | request are processed concurrently using Fibers. This can improve
    | response times when components perform independent I/O operations.
    |
    | Disabled by default: Blade and Livewire rendering rely on global state
    | (view data stacks, component and slot stacks, shared sections) that
    | interleaving fibers can corrupt. Enable only when every component
    | updated in one request is known to be safe to render interleaved.
    |
    */

    'concurrent' => false,

    /*
    |--------------------------------------------------------------------------
    | Max Concurrent Components
    |--------------------------------------------------------------------------
    |
    | The maximum number of Livewire components that can be updated
    | concurrently within a single request.
    |
    */

    'max_concurrent' => 10,

];
