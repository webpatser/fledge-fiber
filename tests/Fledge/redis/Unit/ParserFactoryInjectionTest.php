<?php

use Fledge\Async\Redis\Connection\SocketRedisConnection;
use Fledge\Async\Redis\Connection\SocketRedisConnector;
use Fledge\Async\Redis\Protocol\ParserInterface;
use Fledge\Async\Redis\Protocol\RespParser;

it('RespParser implements ParserInterface', function () {
    $reflection = new ReflectionClass(RespParser::class);

    expect($reflection->implementsInterface(ParserInterface::class))->toBeTrue();
});

it('SocketRedisConnection accepts an optional parser factory closure', function () {
    $reflection = new ReflectionClass(SocketRedisConnection::class);
    $params = $reflection->getConstructor()->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[1]->getName())->toBe('parserFactory');
    expect($params[1]->allowsNull())->toBeTrue();
    expect($params[1]->isDefaultValueAvailable())->toBeTrue();
    expect($params[1]->getDefaultValue())->toBeNull();

    $type = $params[1]->getType();
    expect($type)->not->toBeNull();
    expect((string) $type)->toBe('?Closure');
});

it('SocketRedisConnector accepts an optional parser factory closure', function () {
    $reflection = new ReflectionClass(SocketRedisConnector::class);
    $params = $reflection->getConstructor()->getParameters();

    $byName = [];
    foreach ($params as $param) {
        $byName[$param->getName()] = $param;
    }

    expect($byName)->toHaveKey('parserFactory');
    expect($byName['parserFactory']->allowsNull())->toBeTrue();
    expect($byName['parserFactory']->getDefaultValue())->toBeNull();
    expect((string) $byName['parserFactory']->getType())->toBe('?Closure');
});

it('createRedisConnector accepts a parser factory parameter', function () {
    $reflection = new ReflectionFunction('Fledge\\Async\\Redis\\createRedisConnector');
    $params = $reflection->getParameters();

    $names = array_map(fn (ReflectionParameter $p) => $p->getName(), $params);
    expect($names)->toContain('parserFactory');

    $factoryParam = $params[array_search('parserFactory', $names, true)];
    expect($factoryParam->allowsNull())->toBeTrue();
    expect($factoryParam->getDefaultValue())->toBeNull();
});

it('createRedisClient accepts a parser factory parameter', function () {
    $reflection = new ReflectionFunction('Fledge\\Async\\Redis\\createRedisClient');
    $params = $reflection->getParameters();

    $names = array_map(fn (ReflectionParameter $p) => $p->getName(), $params);
    expect($names)->toContain('parserFactory');
});

it('SocketRedisConnector stores the parser factory for later use', function () {
    $factory = static fn (\Closure $push): ParserInterface => new RespParser($push);

    $connector = new SocketRedisConnector(
        uri: 'tcp://127.0.0.1:6379',
        connectContext: new \Fledge\Async\Stream\ConnectContext(),
        parserFactory: $factory,
    );

    $reflection = new ReflectionClass($connector);
    $property = $reflection->getProperty('parserFactory');

    expect($property->getValue($connector))->toBe($factory);
});

it('SocketRedisConnector defaults parser factory to null', function () {
    $connector = new SocketRedisConnector(
        uri: 'tcp://127.0.0.1:6379',
        connectContext: new \Fledge\Async\Stream\ConnectContext(),
    );

    $reflection = new ReflectionClass($connector);
    $property = $reflection->getProperty('parserFactory');

    expect($property->getValue($connector))->toBeNull();
});
