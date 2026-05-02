<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Cluster\ClusteringRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Support\Arr;

use function Fledge\Async\Redis\createRedisConnector;

class FledgeRedisConnector implements Connector
{
    /**
     * Create a new connection to a Redis server.
     */
    public function connect(array $config, array $options): FledgeRedisConnection
    {
        $formattedOptions = Arr::pull($config, 'options', []);

        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }

        $merged = array_merge($config, $options, $formattedOptions);

        $prefix = $merged['prefix'] ?? '';
        $uri = $this->buildUri($merged);

        $connector = createRedisConnector($uri);

        $connectorCallback = fn () => new RedisClient(new ReconnectingRedisLink($connector));

        $client = $connectorCallback();
        $subscriber = new RedisSubscriber($connector);

        return new FledgeRedisConnection($client, $subscriber, $connectorCallback, $merged, $prefix);
    }

    /**
     * Create a new clustered connection.
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): FledgeRedisClusterConnection
    {
        $shared = array_merge($options, $clusterOptions);
        $prefix = $shared['prefix'] ?? '';

        $seedUris = array_map(fn (array $node) => $this->buildUri(array_merge($shared, $node)), $config);

        $uriForEndpoint = function (string $endpoint) use ($shared): string {
            [$host, $port] = self::splitEndpoint($endpoint);

            return $this->buildUri(array_merge($shared, ['host' => $host, 'port' => $port]));
        };

        $linkFactory = fn () => new ClusteringRedisLink($seedUris, $uriForEndpoint);

        $link = $linkFactory();
        $client = new RedisClient($link);
        $reconnect = function () use ($linkFactory) {
            return new RedisClient($linkFactory());
        };

        return new FledgeRedisClusterConnection(
            $client,
            $link,
            null,
            $reconnect,
            $shared,
            $prefix,
            $uriForEndpoint,
        );
    }

    /**
     * @return array{string, int}
     */
    protected static function splitEndpoint(string $endpoint): array
    {
        if (\str_starts_with($endpoint, '[')) {
            $closing = \strpos($endpoint, ']');

            if ($closing !== false) {
                return [\substr($endpoint, 1, $closing - 1), (int) \substr($endpoint, $closing + 2)];
            }
        }

        $colon = \strrpos($endpoint, ':');

        if ($colon === false) {
            return [$endpoint, 6379];
        }

        return [\substr($endpoint, 0, $colon), (int) \substr($endpoint, $colon + 1)];
    }

    /**
     * Build a Redis URI from the given configuration array.
     */
    protected function buildUri(array $config): string
    {
        $scheme = $config['scheme'] ?? 'tcp';
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 6379);

        // Handle unix sockets
        if (($scheme === 'unix' || isset($config['path'])) && ! isset($config['host'])) {
            $path = $config['path'] ?? $host;

            return "unix://{$path}";
        }

        // Build the base URI
        $uri = "tcp://{$host}:{$port}";

        // Add query params for auth and database
        $query = [];

        if (! empty($config['password'])) {
            $query['password'] = $config['password'];
        }

        if (isset($config['database']) && (int) $config['database'] !== 0) {
            $query['database'] = (int) $config['database'];
        }

        if (isset($config['timeout'])) {
            $query['timeout'] = (float) $config['timeout'];
        }

        if (! empty($query)) {
            $uri .= '?'.http_build_query($query);
        }

        return $uri;
    }
}
