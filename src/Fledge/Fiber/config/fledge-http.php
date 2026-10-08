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
    | integration. Null means unlimited.
    |
    */

    'pool_per_host' => [
        'mail' => null,
        'broadcasting' => null,
        's3' => null,
        'elasticsearch' => 8,
    ],

];
