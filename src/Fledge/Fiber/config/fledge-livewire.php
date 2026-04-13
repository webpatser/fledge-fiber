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
    */

    'concurrent' => true,

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
