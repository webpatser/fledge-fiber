<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\Redis\Connection\Authenticator;
use Fledge\Async\Redis\Connection\DatabaseSelector;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnector;
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

    $connector ??= new SocketRedisConnector(
        $config->getConnectUri(),
        (new ConnectContext())->withConnectTimeout($config->getTimeout()),
        parserFactory: $parserFactory,
    );

    if ($config->hasPassword()) {
        $connector = new Authenticator($config->getPassword(), $connector);
    }

    if ($config->getDatabase() !== 0) {
        $connector = new DatabaseSelector($config->getDatabase(), $connector);
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
