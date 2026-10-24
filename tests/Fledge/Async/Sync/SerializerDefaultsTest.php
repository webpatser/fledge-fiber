<?php

declare(strict_types=1);

use Carbon\Carbon;
use Fledge\Async\Http\Server\Session\LocalSessionStorage;
use Fledge\Async\Http\Server\Session\RedisSessionStorage;
use Fledge\Async\Redis\RedisCache;
use Fledge\Async\Redis\Sync\RedisMutex;
use Fledge\Async\Redis\Sync\RedisParcel;
use Fledge\Async\Serialization\CompressingSerializer;
use Fledge\Async\Serialization\NativeSerializer;
use Fledge\Async\Serialization\Serializer;
use Fledge\Async\Stream\Pipe;
use Fledge\Async\Stream\StreamChannel;
use Fledge\Async\Sync\LocalMutex;
use Fledge\Async\Sync\SharedMemoryParcel;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Cache, parcel, session and channel data with objects (Carbon, enums, DTOs) must keep round-tripping
 * by default; a strict NativeSerializer allow-list is an explicit hardening opt-in.
 */
enum SerializerDefaultsStatus: string
{
    case Active = 'active';
}

final class SerializerDefaultsDto
{
    public function __construct(public string $name, public int $count)
    {
    }
}

/** @return array<string, mixed> */
function serializerDefaultsPayload(): array
{
    return [
        'at' => Carbon::parse('2026-10-24 12:00:00', 'Europe/Amsterdam'),
        'status' => SerializerDefaultsStatus::Active,
        'dto' => new SerializerDefaultsDto('fledge', 3),
        'name' => 'plain',
    ];
}

/** @param array<string, mixed> $data */
function expectPayloadRoundTripped(mixed $data): void
{
    expect($data['at'])->toBeInstanceOf(Carbon::class)
        ->and($data['at']->toIso8601String())->toBe('2026-10-24T12:00:00+02:00')
        ->and($data['status'])->toBe(SerializerDefaultsStatus::Active)
        ->and($data['dto'])->toBeInstanceOf(SerializerDefaultsDto::class)
        ->and($data['dto']->count)->toBe(3)
        ->and($data['name'])->toBe('plain');
}

function constructorDefaultSerializer(string $class): Serializer
{
    foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
        if ($parameter->getName() === 'serializer') {
            return $parameter->getDefaultValue();
        }
    }

    throw new LogicException("{$class} has no serializer parameter");
}

function serializerDefaultsRedisUri(): ?string
{
    $host = test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1');
    $port = (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379);
    $socket = @fsockopen($host, $port, $errno, $errstr, 1);

    if (! $socket) {
        return null;
    }

    fclose($socket);

    return "redis://{$host}:{$port}";
}

beforeEach(function () {
    // Channel I/O fires-and-forgets a lock release Future, which emits an unrelated
    // #[\NoDiscard] warning; swallow only that one.
    set_error_handler(static fn (int $severity, string $message): bool => str_contains($message, 'NoDiscard')
        || str_contains($message, 'should either be used or intentionally ignored'));
});

afterEach(function () {
    restore_error_handler();
});

it('round-trips Carbon, enums and DTOs through every default serializer', function (string $class) {
    $serializer = constructorDefaultSerializer($class);

    expectPayloadRoundTripped($serializer->unserialize($serializer->serialize(serializerDefaultsPayload())));
})->with([
    RedisCache::class,
    RedisSessionStorage::class,
    LocalSessionStorage::class,
]);

it('round-trips objects through RedisParcel default serializer', function () {
    // The client connects lazily, so no Redis server is needed to build the parcel.
    $mutex = new RedisMutex(createRedisClient('redis://127.0.0.1:1'));
    $parcel = (fn () => new RedisParcel($mutex, 'serializer-defaults'))->call(
        (new ReflectionClass(RedisParcel::class))->newInstanceWithoutConstructor(),
    );
    $serializer = (new ReflectionProperty($parcel, 'serializer'))->getValue($parcel);

    expectPayloadRoundTripped($serializer->unserialize($serializer->serialize(serializerDefaultsPayload())));
});

it('keeps objects in LocalSessionStorage sessions by default', function () {
    $storage = new LocalSessionStorage();
    $storage->write('sid', serializerDefaultsPayload());

    expectPayloadRoundTripped($storage->read('sid'));
});

it('refuses classes in LocalSessionStorage sessions in strict mode', function () {
    $storage = new LocalSessionStorage(new CompressingSerializer(new NativeSerializer([])));
    $storage->write('sid', serializerDefaultsPayload());

    $data = $storage->read('sid');

    expect($data['at'])->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and($data['dto'])->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and($data['name'])->toBe('plain');
});

it('keeps enums but refuses unlisted classes in strict mode', function () {
    // PHP never gates enum cases by allowed_classes: they cannot run code on wakeup.
    $strict = new NativeSerializer([]);
    expect($strict->unserialize($strict->serialize(SerializerDefaultsStatus::Active)))->toBe(SerializerDefaultsStatus::Active)
        ->and($strict->unserialize($strict->serialize(new SerializerDefaultsDto('a', 1))))->toBeInstanceOf(__PHP_Incomplete_Class::class);

    $listed = new NativeSerializer([SerializerDefaultsDto::class]);
    expect($listed->unserialize($listed->serialize(new SerializerDefaultsDto('a', 1))))->toBeInstanceOf(SerializerDefaultsDto::class)
        ->and($listed->unserialize($listed->serialize(new ArrayObject())))->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('keeps objects in RedisCache and RedisSessionStorage by default and refuses them in strict mode', function () {
    $uri = serializerDefaultsRedisUri() ?? $this->markTestSkipped('Redis not available');
    $client = createRedisClient($uri);
    $key = 'serializer-defaults:' . bin2hex(random_bytes(6));

    $cache = new RedisCache($client);
    $cache->set($key, serializerDefaultsPayload(), 60);
    expectPayloadRoundTripped($cache->get($key));

    $strict = new RedisCache($client, new NativeSerializer([]));
    expect($strict->get($key)['dto'])->toBeInstanceOf(__PHP_Incomplete_Class::class);
    $cache->delete($key);

    $sessions = new RedisSessionStorage($client, keyPrefix: $key . ':');
    $sessions->write('sid', serializerDefaultsPayload());
    expectPayloadRoundTripped($sessions->read('sid'));

    $strictSessions = new RedisSessionStorage($client, new CompressingSerializer(new NativeSerializer([])), keyPrefix: $key . ':');
    expect($strictSessions->read('sid')['at'])->toBeInstanceOf(__PHP_Incomplete_Class::class);
    $sessions->write('sid', []);
});

it('keeps objects in SharedMemoryParcel by default and refuses them in strict mode', function () {
    if (! extension_loaded('shmop')) {
        $this->markTestSkipped('shmop extension not loaded');
    }

    $parcel = SharedMemoryParcel::create(new LocalMutex(), serializerDefaultsPayload());
    expectPayloadRoundTripped($parcel->unwrap());

    $parcel = SharedMemoryParcel::create(new LocalMutex(), new SerializerDefaultsDto('x', 1), serializer: new NativeSerializer([]));
    expect($parcel->unwrap())->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('passes objects over a StreamChannel by default and refuses them in strict mode', function () {
    $pipe = new Pipe(1 << 16);
    $channel = new StreamChannel($pipe->getSource(), $pipe->getSink());
    $channel->send(serializerDefaultsPayload());
    expectPayloadRoundTripped($channel->receive());

    $pipe = new Pipe(1 << 16);
    $channel = new StreamChannel($pipe->getSource(), $pipe->getSink(), new NativeSerializer([]));
    $channel->send(new SerializerDefaultsDto('x', 1));
    expect($channel->receive())->toBeInstanceOf(__PHP_Incomplete_Class::class);
});
