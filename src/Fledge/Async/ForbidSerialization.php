<?php declare(strict_types=1);

namespace Fledge\Async;

trait ForbidSerialization
{
    final public function __serialize(): never
    {
        throw new \Error(__CLASS__ . ' does not support serialization');
    }

    final public function __unserialize(array $data): never
    {
        throw new \Error(__CLASS__ . ' does not support deserialization');
    }
}
