<?php

namespace Fledge\Fiber\Redis;

use Closure;
use Fledge\Async\Redis\Cluster\ClusteringRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisSubscriber;
use InvalidArgumentException;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;
use function Fledge\Async\Redis\createRedisConnector;

class FledgeRedisClusterConnection extends FledgeRedisConnection
{
    /**
     * Build a URI for an arbitrary cluster node endpoint, applying shared auth/timeout options.
     */
    protected Closure $uriForEndpoint;

    protected ClusteringRedisLink $link;

    public function __construct(
        RedisClient $client,
        ClusteringRedisLink $link,
        ?RedisSubscriber $subscriber,
        ?callable $connector,
        array $config,
        string $prefix,
        Closure $uriForEndpoint,
    ) {
        parent::__construct($client, $subscriber, $connector, $config, $prefix);

        $this->link = $link;
        $this->uriForEndpoint = $uriForEndpoint;
    }

    public function select($database)
    {
        if ((int) $database !== 0) {
            throw new InvalidArgumentException('Redis Cluster does not support SELECT to a non-zero database.');
        }

        return null;
    }

    public function flushdb()
    {
        $async = strtoupper((string) (func_get_args()[0] ?? '')) === 'ASYNC';
        $params = $async ? ['ASYNC'] : [];

        return $this->fanOutToMasters(fn (string $endpoint) => $this->commandOn($endpoint, 'flushdb', $params));
    }

    public function flushall()
    {
        return $this->fanOutToMasters(fn (string $endpoint) => $this->commandOn($endpoint, 'flushall', []));
    }

    /**
     * Match {@see \Illuminate\Redis\Connections\PhpRedisClusterConnection::scan()}: scan
     * a single master per call. Default node is the first master; override via
     * `$options['node']` (host:port string).
     *
     * Returns `[$cursor, $keys]`, or `false` when starting from cursor 0 returns no keys.
     */
    public function scan($cursor, $options = [])
    {
        $node = $options['node'] ?? $this->defaultNode();

        $args = [(string) $cursor];

        if (isset($options['match'])) {
            $args[] = 'MATCH';
            $args[] = $options['match'];
        }

        if (isset($options['count'])) {
            $args[] = 'COUNT';
            $args[] = $options['count'];
        }

        $result = $this->commandOn($node, 'scan', $args);

        if (! is_array($result)) {
            return false;
        }

        $newCursor = (string) $result[0];
        $keys = $result[1] ?? [];

        if ((string) $cursor === '0' && $keys === [] && $newCursor === '0') {
            return false;
        }

        return [$newCursor, $keys];
    }

    /**
     * Match {@see \Illuminate\Redis\Connections\PredisClusterConnection::keys()}:
     * fan out across all masters and merge.
     *
     * @param  string  $pattern
     * @return array
     */
    public function keys($pattern = '*')
    {
        $results = $this->fanOutToMasters(fn (string $endpoint) => $this->commandOn($endpoint, 'keys', [$pattern]));

        $merged = [];

        foreach ($results as $nodeKeys) {
            if (is_array($nodeKeys)) {
                array_push($merged, ...$nodeKeys);
            }
        }

        return $merged;
    }

    public function isCluster()
    {
        return true;
    }

    /**
     * Default node used by `scan()` when no `node` option is provided.
     */
    protected function defaultNode(): string
    {
        $masters = $this->clusterLink()->masters();

        if ($masters === []) {
            throw new \InvalidArgumentException('Unable to determine default node. No master nodes found in the cluster.');
        }

        return $masters[0];
    }

    public function subscribe($channels, \Closure $callback)
    {
        $this->buildSubscriberForRandomNode();

        parent::subscribe($channels, $callback);
    }

    public function psubscribe($channels, \Closure $callback)
    {
        $this->buildSubscriberForRandomNode();

        parent::psubscribe($channels, $callback);
    }

    /**
     * @param  Closure(string $endpoint): mixed  $task
     * @return list<mixed>
     */
    protected function fanOutToMasters(Closure $task): array
    {
        $masters = $this->clusterLink()->masters();

        $futures = [];

        foreach ($masters as $endpoint) {
            $futures[] = async(fn () => $task($endpoint));
        }

        return await($futures);
    }

    protected function commandOn(string $endpoint, string $method, array $parameters): mixed
    {
        $args = $this->flattenParameters($parameters);

        return $this->clusterLink()->executeOn($endpoint, strtoupper($method), $args)->unwrap();
    }

    protected function clusterLink(): ClusteringRedisLink
    {
        return $this->link;
    }

    protected function buildSubscriberForRandomNode(): void
    {
        if ($this->subscriber !== null) {
            return;
        }

        $masters = $this->clusterLink()->masters();
        $endpoint = $masters[array_rand($masters)];
        $uri = ($this->uriForEndpoint)($endpoint);

        $this->subscriber = new RedisSubscriber(createRedisConnector($uri));
    }
}
