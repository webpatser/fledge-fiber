<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Cluster\ClusteringRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisException;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\Connection\BackoffStrategy;
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
        $redisConfig = $this->buildConfig($merged);

        $connector = createRedisConnector($redisConfig);

        $policy = $redisConfig->getRetryPolicy();
        $readTimeout = $redisConfig->getReadTimeout();
        $backoff = BackoffStrategy::fromRetryPolicy($policy);

        $connectorCallback = fn () => new RedisClient(new ReconnectingRedisLink(
            $connector,
            $readTimeout,
            $backoff,
            $policy->maxRetries,
        ));

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
        $this->assertSupportedClusterOptions($shared);

        $prefix = $shared['prefix'] ?? '';

        $seedConfigs = array_map(
            fn (array $node) => $this->buildConfig(array_merge($shared, $node)),
            array_values($config),
        );

        $configForEndpoint = function (string $endpoint) use ($shared): RedisConfig {
            [$host, $port] = self::splitEndpoint($endpoint);

            return $this->buildConfig(array_merge($shared, ['host' => $host, 'port' => $port]));
        };

        $linkFactory = fn () => new ClusteringRedisLink($seedConfigs, $configForEndpoint);

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
            $configForEndpoint,
        );
    }

    /**
     * Reject cluster options that would silently change semantics: replica
     * read routing is not implemented (every read goes to a master), and
     * predis-style client-side sharding is not a real Redis Cluster.
     *
     * @throws RedisException
     */
    protected function assertSupportedClusterOptions(array $shared): void
    {
        $failover = $shared['failover'] ?? null;
        $normalized = is_string($failover) ? strtolower($failover) : $failover;

        // RedisCluster::FAILOVER_DISTRIBUTE = 2, FAILOVER_DISTRIBUTE_SLAVES = 3.
        if (in_array($normalized, ['distribute', 'distribute_slaves', 2, 3], true)) {
            throw new RedisException(sprintf(
                'Replica read routing (failover=%s) is not supported by the Fledge Redis driver; use failover=none or failover=error.',
                is_string($failover) ? $failover : (string) $failover,
            ));
        }

        $cluster = $shared['cluster'] ?? 'redis';

        if ($cluster !== 'redis') {
            throw new RedisException(sprintf(
                'Cluster driver [%s] is not supported by the Fledge Redis driver: predis-style client-side sharding is unavailable, only options.cluster = "redis" (Redis Cluster) is supported.',
                is_scalar($cluster) ? (string) $cluster : gettype($cluster),
            ));
        }
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
     * Build a structured RedisConfig from a merged Laravel configuration
     * array, preserving options that do not survive a round-trip through a
     * URI (read_timeout, TLS context, client name, retry policy, keepalive).
     */
    protected function buildConfig(array $merged): RedisConfig
    {
        if (isset($merged['context'])) {
            $merged['context'] = $this->normalizeContext((array) $merged['context']);
        }

        return RedisConfig::fromParameters($merged);
    }

    /**
     * Normalize the SSL context options to a flat ssl option array, accepting
     * the same shapes as upstream PhpRedisConnector::normalizeContext():
     * ['stream' => [...]], ['ssl' => [...]], or already-flat options.
     */
    protected function normalizeContext(array $context): array
    {
        if (isset($context['stream']) && \is_array($context['stream'])) {
            return $context['stream'];
        }

        if (isset($context['ssl']) && \is_array($context['ssl'])) {
            return $context['ssl'];
        }

        return $context;
    }

}
