<?php declare(strict_types=1);

namespace Fledge\Async\Serialization;

final class NativeSerializer implements Serializer
{
    /** @var list<class-string>|null */
    private ?array $allowedClasses;

    /**
     * @param list<class-string>|null $allowedClasses List of allowed class names to be unserialized.
     *  Null (the default) allows any class, matching stock PHP and Laravel's cache default, so stored
     *  Carbon instances, enums and DTOs round-trip. Null also lets anyone who can write the payload
     *  (a shared Redis, a shared memory segment, a stream peer) instantiate gadget classes. As hardening,
     *  pass the classes you store (or an empty array for none; objects then become __PHP_Incomplete_Class)
     *  as the `serializer` argument of RedisCache, RedisParcel, SharedMemoryParcel, RedisSessionStorage,
     *  LocalSessionStorage or StreamChannel. Enum cases always unserialize: PHP does not gate them.
     */
    public function __construct(?array $allowedClasses = null)
    {
        $this->allowedClasses = $allowedClasses;
    }

    #[\Override]
    public function serialize($data): string
    {
        try {
            return \serialize($data);
        } catch (\Throwable $exception) {
            throw new SerializationException(
                \sprintf('The given data could not be serialized: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    #[\Override]
    public function unserialize(string $data)
    {
        try {
            $result = \unserialize($data, ['allowed_classes' => $this->allowedClasses ?? true]);

            if ($result === false && $data !== \serialize(false)) {
                throw new SerializationException(
                    'Invalid data provided to unserialize: ' . encodeUnprintableChars($data)
                );
            }
        } catch (\Throwable $exception) {
            throw new SerializationException('Exception thrown when unserializing data', 0, $exception);
        }

        return $result;
    }
}
