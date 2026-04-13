<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Session;

use Fledge\Async\Redis\Command\Option\SetOptions;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Serialization\CompressingSerializer;
use Fledge\Async\Serialization\NativeSerializer;
use Fledge\Async\Serialization\Serializer;

final class RedisSessionStorage implements SessionStorage
{
    public const DEFAULT_SESSION_LIFETIME = 3600;

    private readonly SetOptions $setOptions;

    public function __construct(
        private readonly RedisClient $client,
        private readonly Serializer $serializer = new CompressingSerializer(new NativeSerializer()),
        private readonly int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME,
        private readonly string $keyPrefix = 'session:'
    ) {
        $this->setOptions = (new SetOptions())->withTtl($this->sessionLifetime);
    }

    public function write(string $id, array $data): void
    {
        if (empty($data)) {
            try {
                $this->client->delete($this->keyPrefix . $id);
            } catch (\Throwable $error) {
                throw new SessionException("Couldn't delete session '{$id}''", 0, $error);
            }

            return;
        }

        try {
            $serializedData = $this->serializer->serialize($data);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't serialize data for session '{$id}'", 0, $error);
        }

        try {
            $this->client->set($this->keyPrefix . $id, $serializedData, $this->setOptions);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't persist data for session '{$id}'", 0, $error);
        }
    }

    public function read(string $id): array
    {
        try {
            $result = $this->client->get($this->keyPrefix . $id);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '{$id}'", 0, $error);
        }

        if ($result === null) {
            return [];
        }

        try {
            $data = $this->serializer->unserialize($result);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't read data for session '{$id}'", 0, $error);
        }

        try {
            $this->client->expireIn($this->keyPrefix . $id, $this->sessionLifetime);
        } catch (\Throwable $error) {
            throw new SessionException("Couldn't renew expiry for session '{$id}'", 0, $error);
        }

        return $data;
    }
}
