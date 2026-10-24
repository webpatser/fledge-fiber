<?php

use Fledge\Async\Http\Server\Session\SessionIdGenerator;
use Fledge\Async\Http\Server\Session\LocalSessionStorage;
use Fledge\Async\Http\Server\Session\Session;
use Fledge\Async\Sync\LocalKeyedMutex;

function sessionIdGenerator(): SessionIdGenerator
{
    return new class implements SessionIdGenerator
    {
        public function generate(): string
        {
            return bin2hex(random_bytes(16));
        }

        public function validate(string $id): bool
        {
            return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
        }
    };
}

function makeSession(LocalSessionStorage $storage, ?string $clientId): Session
{
    return new Session(new LocalKeyedMutex, $storage, sessionIdGenerator(), $clientId);
}

it('discards a client-supplied session id that has no stored data', function () {
    $storage = new LocalSessionStorage;
    $attackerId = (sessionIdGenerator())->generate();

    $session = makeSession($storage, $attackerId);
    expect($session->getId())->toBe($attackerId);

    $session->lock();

    expect($session->getId())->not->toBe($attackerId)->and($session->getId())->not->toBeNull();

    $session->unlock();
});

it('keeps a client-supplied session id that has stored data', function () {
    $storage = new LocalSessionStorage;

    $first = makeSession($storage, null);
    $first->lock();
    $first->set('user', 42);
    $first->commit();
    $id = $first->getId();

    $second = makeSession($storage, $id);
    $second->lock();

    expect($second->getId())->toBe($id)->and($second->get('user'))->toBe(42);

    $second->unlock();
});
