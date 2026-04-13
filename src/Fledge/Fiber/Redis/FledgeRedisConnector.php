<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Support\Arr;
use InvalidArgumentException;

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
     *
     * @throws \InvalidArgumentException
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): never
    {
        throw new InvalidArgumentException(
            'The Fledge Async Redis driver does not support Redis Cluster. Use phpredis or predis for cluster connections.'
        );
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
