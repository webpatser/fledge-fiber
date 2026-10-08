<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-party HTTP Integrations
    |--------------------------------------------------------------------------
    |
    | Integrations that build their own HTTP client (mail transports,
    | broadcasting, S3, Elasticsearch) can run on the Fledge handler so their
    | requests are non-blocking inside fibers. Switch one off to fall back to
    | the library's default client. Only scalars here, so config:cache is safe.
    |
    */

    'integrations' => [
        'mail' => (bool) env('FLEDGE_HTTP_MAIL', true),
        'broadcasting' => (bool) env('FLEDGE_HTTP_BROADCASTING', true),
        's3' => (bool) env('FLEDGE_HTTP_S3', true),
        'elasticsearch' => (bool) env('FLEDGE_HTTP_ELASTICSEARCH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Open Connections Per Host
    |--------------------------------------------------------------------------
    |
    | Upper bound on simultaneously open connections to one host, per
    | integration. Null means unlimited. A mailer's own
    | `client.max_host_connections` wins over the mail value.
    |
    */

    'pool_per_host' => [
        'mail' => null,
        'broadcasting' => 8,
        's3' => null,
        'elasticsearch' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | fiberio Hooks
    |--------------------------------------------------------------------------
    |
    | Which php-fiberio (>= 0.2) hooks the native MySQL/MariaDB driver turns
    | on: a comma list of sleep, dns, ssl, or `all` / `none`. A connection's
    | own `fiberio_hooks` key wins over this value. fiberio is enabled once
    | per process, so the first native connection decides. Read through
    | config (not getenv) so it keeps working under config:cache.
    |
    */

    'fiberio_hooks' => env('FLEDGE_FIBERIO_HOOKS', 'all'),

];
