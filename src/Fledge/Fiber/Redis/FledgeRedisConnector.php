<?php

namespace Fledge\Fiber\Redis;

use Fledge\Async\Redis\Cluster\ClusteringRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
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

        $this->rejectUnsupportedOptions($merged);

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
        $this->rejectUnsupportedOptions($shared, cluster: true);

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
     * Reject configuration options that would silently change data or
     * routing semantics if ignored. Options without semantic impact (scan,
     * persistent, persistent_id, compression_level without compression) are
     * tolerated silently.
     *
     * The failover and cluster driver checks only apply to cluster
     * connections ($cluster = true); upstream ignores both options on single
     * connections even when the global options array carries them.
     *
     * @throws UnsupportedRedisOptionException
     */
    protected function rejectUnsupportedOptions(array $options, bool $cluster = false): void
    {
        // Redis::SERIALIZER_NONE = 0: values would be transparently
        // (un)serialized by phpredis; ignoring the option corrupts reads.
        $serializer = $options['serializer'] ?? null;

        if ($serializer !== null && $serializer !== 0 && $serializer !== 'none') {
            throw new UnsupportedRedisOptionException(sprintf(
                'The serializer option [%s] is not supported by the Fledge Redis driver; values are stored raw. Remove the option or use serializer=none.',
                is_scalar($serializer) ? (string) $serializer : gettype($serializer),
            ));
        }

        // Redis::COMPRESSION_NONE = 0.
        $compression = $options['compression'] ?? null;

        if ($compression !== null && $compression !== 0 && $compression !== 'none') {
            throw new UnsupportedRedisOptionException(sprintf(
                'The compression option [%s] is not supported by the Fledge Redis driver; values are stored raw. Remove the option or use compression=none.',
                is_scalar($compression) ? (string) $compression : gettype($compression),
            ));
        }

        if (! empty($options['pack_ignore_numbers'])) {
            throw new UnsupportedRedisOptionException(
                'The pack_ignore_numbers option is not supported by the Fledge Redis driver; it only changes behavior together with a serializer, which is unsupported.',
            );
        }

        if (($options['replication'] ?? null) === 'sentinel') {
            throw new UnsupportedRedisOptionException(
                'Redis Sentinel (replication=sentinel) is not supported by the Fledge Redis driver; use a direct connection or predis.',
            );
        }

        if (! $cluster) {
            return;
        }

        $failover = $options['failover'] ?? null;
        $normalized = is_string($failover) ? strtolower($failover) : $failover;

        // RedisCluster::FAILOVER_DISTRIBUTE = 2, FAILOVER_DISTRIBUTE_SLAVES = 3.
        if (in_array($normalized, ['distribute', 'distribute_slaves', 2, 3], true)) {
            throw new UnsupportedRedisOptionException(sprintf(
                'Replica read routing (failover=%s) is not supported by the Fledge Redis driver; use failover=none or failover=error.',
                is_string($failover) ? $failover : (string) $failover,
            ));
        }

        $cluster = $options['cluster'] ?? 'redis';

        if ($cluster !== 'redis') {
            throw new UnsupportedRedisOptionException(sprintf(
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
