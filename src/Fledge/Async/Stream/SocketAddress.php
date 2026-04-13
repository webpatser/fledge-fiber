<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

interface SocketAddress extends \Stringable
{
    public function toString(): string;

    public function getType(): SocketAddressType;
}
