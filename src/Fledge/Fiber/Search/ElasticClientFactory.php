<?php

declare(strict_types=1);

namespace Fledge\Fiber\Search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Transport\NodePool\NodePoolInterface;
use Fledge\Fiber\Http\FledgeGuzzle;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Container\Container;
use InvalidArgumentException;

/**
 * Builds Elasticsearch clients whose HTTP transport runs on Fledge's fiber-aware
 * HTTP client instead of a blocking curl handler.
 *
 * elasticsearch-php discovers a bare GuzzleHttp\Client through PSR-18 discovery,
 * which bypasses the FledgeHandler that Fledge installs only for the Http facade.
 * Inside a Torque worker that made every bulk() suspend the whole process; with
 * this factory the request suspends only the Fiber that issued it.
 *
 * The handler is passed through setHttpClientOptions() as well as setHttpClient():
 * ClientBuilder rebuilds the Guzzle client from its own SSL settings
 * (setSSLVerification(), setCABundle()) and only carries over the options given
 * there, so a handler set on the client alone is lost and curl takes over.
 *
 * Hosts go through a ResurrectingNodePool (see nodePool()), so one network error
 * no longer takes a long-running process off Elasticsearch until it restarts.
 */
final class ElasticClientFactory
{
    /**
     * Connections per ES host and process. Fledge's default pool is unlimited;
     * during the nightly RDW sync that plus curl leftovers exhausted the worker's
     * file descriptors (2026-08-28).
     */
    public const int DEFAULT_CONNECTIONS_PER_HOST = 8;

    private ?GuzzleClient $http = null;

    private ?Client $client = null;

    /**
     * @param  int|null  $connectionsPerHost  Open connections per ES authority and process,
     *                                        null for unlimited. The provider reads it from
     *                                        `fledge-http.pool_per_host.elasticsearch`
     *                                        (default 8; an explicit null means unlimited).
     */
    public function __construct(private readonly ?int $connectionsPerHost = self::DEFAULT_CONNECTIONS_PER_HOST) {}

    /**
     * Shared client for the app-wide `elasticsearch` connection config.
     */
    public function client(): Client
    {
        return $this->client ??= $this->builder(Container::getInstance()->make('config')->get('database.connections.elasticsearch'))->build();
    }

    /**
     * A ClientBuilder pre-configured with hosts, credentials and the fiber-aware transport.
     *
     * @param  array<string, mixed>  $config  A `database.connections.*` block with the PDPhilip shape.
     */
    public function builder(array $config): ClientBuilder
    {
        $builder = ClientBuilder::create()
            ->setHttpClient($this->httpClient())
            ->setHttpClientOptions(['handler' => FledgeGuzzle::stack($this->connectionsPerHost)]);

        if (($nodePool = $this->nodePool($config)) !== null) {
            $builder->setNodePool($nodePool);
        }

        if (($config['auth_type'] ?? 'http') === 'cloud' && ! empty($config['cloud_id'])) {
            $builder->setElasticCloudId($config['cloud_id']);
        } else {
            $builder->setHosts((array) ($config['hosts'] ?? ['http://localhost:9200']));
        }

        if (! empty($config['username']) && ! empty($config['password'])) {
            $builder->setBasicAuthentication($config['username'], $config['password']);
        }

        if (! empty($config['api_key'])) {
            $builder->setApiKey($config['api_key'], $config['api_id'] ?? null);
        }

        if (! empty($config['ssl_cert'])) {
            $builder->setCABundle($config['ssl_cert']);
        }

        return $builder;
    }

    /**
     * The node pool for a connection, null for the transport default.
     *
     * Defaults to a ResurrectingNodePool: the transport default (SimpleNodePool
     * with NoResurrect) marks the only host dead on the first network error and
     * then refuses every request with "No alive nodes" until the process restarts.
     *
     * Read from the connection config, top level first, then under `options`
     * (where PDPhilip configs keep their client settings):
     * - `node_pool`: a NodePoolInterface instance or class name (resolved through
     *   the container), or false for the transport default.
     * - `resurrect_after`: seconds a dead host is skipped before it is tried again
     *   (default 5). While every host is dead and inside that window, requests
     *   fail fast with NoNodeAvailableException instead of waiting on a dead host.
     *
     * A node pool set on the returned builder with setNodePool() still wins.
     *
     * @param  array<string, mixed>  $config
     */
    private function nodePool(array $config): ?NodePoolInterface
    {
        $pool = $config['node_pool'] ?? $config['options']['node_pool'] ?? null;

        if ($pool === false) {
            return null;
        }

        if ($pool instanceof NodePoolInterface) {
            return $pool;
        }

        if (is_string($pool) && $pool !== '') {
            if (! is_a($pool, NodePoolInterface::class, true)) {
                throw new InvalidArgumentException("Elasticsearch node_pool [{$pool}] does not implement ".NodePoolInterface::class.'.');
            }

            return Container::getInstance()->make($pool);
        }

        $after = $config['resurrect_after'] ?? $config['options']['resurrect_after'] ?? null;

        return new ResurrectingNodePool(
            $after === null || $after === '' ? ResurrectingNodePool::DEFAULT_RESURRECT_AFTER : max(0.0, (float) $after),
        );
    }

    /**
     * One Guzzle client per factory so Fledge's connection pool is reused across requests.
     */
    public function httpClient(): GuzzleClient
    {
        return $this->http ??= FledgeGuzzle::client([], $this->connectionsPerHost);
    }
}
