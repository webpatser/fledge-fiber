<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\Redis\Cluster\ClusteringRedisLink;
use Fledge\Async\Redis\Connection\Authenticator;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\ClientNameSetter;
use Fledge\Async\Redis\Connection\DatabaseSelector;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Connection\SocketRedisConnector;
use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Stream\ConnectContext;

/**
 * @param (\Closure(\Closure(RedisResponse):void):ParserInterface)|null $parserFactory
 *     Optional parser factory passed to the default SocketRedisConnector when
 *     no explicit $connector is supplied. Ignored if $connector is provided.
 */
function createRedisConnector(
    RedisConfig|string $config,
    ?RedisConnector $connector = null,
    ?\Closure $parserFactory = null,
): RedisConnector {
    if (\is_string($config)) {
        $config = RedisConfig::fromUri($config);
    }

    $connectContext = (new ConnectContext())->withConnectTimeout($config->getTimeout());

    if (($tlsContext = $config->getTlsContext()) !== null) {
        $connectContext = $connectContext->withTlsContext($tlsContext);
    }

    $connector ??= new SocketRedisConnector(
        $config->getConnectUri(),
        $connectContext,
        parserFactory: $parserFactory,
        tcpKeepalive: $config->usesTcpKeepalive(),
    );

    if ($config->hasPassword()) {
        $connector = new Authenticator($config->getPassword(), $connector, $config->getUsername());
    }

    if ($config->getDatabase() !== 0) {
        $connector = new DatabaseSelector($config->getDatabase(), $connector);
    }

    if (($clientName = $config->getClientName()) !== null) {
        $connector = new ClientNameSetter($clientName, $connector);
    }

    return $connector;
}

/**
 * @param (\Closure(\Closure(RedisResponse):void):ParserInterface)|null $parserFactory
 *     Optional parser factory passed to the default connector when $connector is null.
 */
function createRedisClient(
    RedisConfig|string $config,
    ?RedisConnector $connector = null,
    ?\Closure $parserFactory = null,
): RedisClient {
    return new RedisClient(new ReconnectingRedisLink(createRedisConnector($config, $connector, $parserFactory)));
}

/**
 * Create a client that routes commands across a Redis Cluster.
 *
 * @param list<RedisConfig|string> $seeds
 *     Seed nodes as "host:port" (or "[v6]:port"), a URI such as "tcp://host:port", or a RedisConfig.
 *     Must not be empty; the topology is discovered from the first reachable seed.
 * @param (\Closure(string):(RedisConfig|string))|null $configForEndpoint
 *     Builds the config for a "host:port" endpoint learned from the topology (defaults to a plain tcp:// URI).
 * @param (\Closure(\Closure(RedisResponse):void):ParserInterface)|null $parserFactory
 *     Optional parser factory used for every node connection.
 *
 * @throws \InvalidArgumentException when $seeds is empty or holds an invalid entry
 */
function createRedisClusterClient(
    array $seeds,
    ?\Closure $configForEndpoint = null,
    ?\Closure $parserFactory = null,
): RedisClient {
    if ($seeds === []) {
        throw new \InvalidArgumentException('createRedisClusterClient() requires at least one seed.');
    }

    $normalized = [];

    foreach ($seeds as $seed) {
        if ($seed instanceof RedisConfig) {
            $normalized[] = $seed;
        } elseif (\is_string($seed) && $seed !== '') {
            $normalized[] = \str_contains($seed, '://') ? $seed : 'tcp://' . $seed;
        } else {
            throw new \InvalidArgumentException('Each cluster seed must be a non-empty string or a RedisConfig.');
        }
    }

    $configForEndpoint ??= static fn (string $endpoint): string => 'tcp://' . $endpoint;

    $linkFactory = $parserFactory === null ? null : static function (RedisConfig|string $config) use ($parserFactory): RedisLink {
        $connector = createRedisConnector($config, null, $parserFactory);

        if (! $config instanceof RedisConfig) {
            return new ReconnectingRedisLink($connector);
        }

        $policy = $config->getRetryPolicy();

        return new ReconnectingRedisLink(
            $connector,
            $config->getReadTimeout(),
            BackoffStrategy::fromRetryPolicy($policy),
            $policy->maxRetries,
        );
    };

    return new RedisClient(new ClusteringRedisLink($normalized, $configForEndpoint, $linkFactory));
}
