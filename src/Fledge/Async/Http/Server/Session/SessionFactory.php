<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Session;

use Fledge\Async\Sync\KeyedMutex;
use Fledge\Async\Sync\LocalKeyedMutex;

final class SessionFactory
{
    public function __construct(
        private readonly KeyedMutex $mutex = new LocalKeyedMutex(),
        private readonly SessionStorage $storage = new LocalSessionStorage(),
        private readonly SessionIdGenerator $idGenerator = new Base64UrlSessionIdGenerator(),
    ) {
    }

    public function create(?string $clientId): Session
    {
        return new Session($this->mutex, $this->storage, $this->idGenerator, $clientId);
    }
}
