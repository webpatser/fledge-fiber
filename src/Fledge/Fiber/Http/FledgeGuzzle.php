<?php

namespace Fledge\Fiber\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * Shared entry point for Guzzle clients running on the Fledge handler.
 *
 * Integrations that build their own Guzzle client (mail transports,
 * broadcasting, S3, Elasticsearch) take their handler stack from here so
 * they all run non-blocking on the Revolt loop instead of curl.
 *
 * Connection pools live in one AsyncClientFactory per per-host limit,
 * shared across every stack and client this class hands out, so keep-alive
 * connections are reused between integrations. Each stack() call still
 * returns a fresh HandlerStack because stacks are mutable and callers push
 * their own middleware onto them.
 */
final class FledgeGuzzle
{
    /** @var array<int, AsyncClientFactory> keyed by per-host limit, 0 for unlimited */
    private static array $factories = [];

    /**
     * The shared client factory for a per-host connection limit.
     *
     * @param  int|null  $perHost  Maximum open connections per authority, null for no limit.
     */
    public static function factory(?int $perHost = null): AsyncClientFactory
    {
        $key = $perHost ?? 0;

        return self::$factories[$key] ??= new AsyncClientFactory($perHost);
    }

    /**
     * A Guzzle handler stack with the production middleware (redirects,
     * cookies, http_errors, prepare-body) on top of the Fledge handler.
     *
     * @param  int|null  $perHost  Maximum open connections per authority, null for no limit.
     */
    public static function stack(?int $perHost = null): HandlerStack
    {
        return HandlerStack::create(new FledgeHandler(self::factory($perHost)));
    }

    /**
     * A Guzzle client on the Fledge handler stack. A 'handler' passed in
     * $options wins over the Fledge stack.
     *
     * @param  array<string, mixed>  $options  Guzzle client options.
     * @param  int|null  $perHost  Maximum open connections per authority, null for no limit.
     */
    public static function client(array $options = [], ?int $perHost = null): Client
    {
        return new Client($options + ['handler' => self::stack($perHost)]);
    }

    /**
     * Drop the shared factories and their connection pools.
     */
    public static function flush(): void
    {
        self::$factories = [];
    }
}
