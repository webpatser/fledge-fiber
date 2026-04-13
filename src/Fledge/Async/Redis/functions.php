<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\Redis\Connection\Authenticator;
use Fledge\Async\Redis\Connection\DatabaseSelector;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisConnector;
use Fledge\Async\Redis\Connection\SocketRedisConnector;
use Fledge\Async\Stream\ConnectContext;

function createRedisConnector(RedisConfig|string $config, ?RedisConnector $connector = null): RedisConnector
{
    if (\is_string($config)) {
        $config = RedisConfig::fromUri($config);
    }

    $connector ??= new SocketRedisConnector(
        $config->getConnectUri(),
        (new ConnectContext())->withConnectTimeout($config->getTimeout())
    );

    if ($config->hasPassword()) {
        $connector = new Authenticator($config->getPassword(), $connector);
    }

    if ($config->getDatabase() !== 0) {
        $connector = new DatabaseSelector($config->getDatabase(), $connector);
    }

    return $connector;
}

function createRedisClient(RedisConfig|string $config, ?RedisConnector $connector = null): RedisClient
{
    return new RedisClient(new ReconnectingRedisLink(createRedisConnector($config, $connector)));
}
