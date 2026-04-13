<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Session\Internal;

use Fledge\Async\Http\Server\Session\SessionIdGenerator;

/** @internal */
final class TestSessionIdGenerator implements SessionIdGenerator
{
    private string $nextId = 'a';

    public function generate(): string
    {
        return $this->nextId++;
    }

    public function validate(string $id): bool
    {
        return (bool) \preg_match('(^[a-z]+$)', $id);
    }
}
