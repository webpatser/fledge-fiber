<?php declare(strict_types=1);

namespace Fledge\Async\Stream\SocketAddress;

use Fledge\Async\Stream\Internal;
use Fledge\Async\Stream\InternetAddress;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\SocketException;
use Fledge\Async\Stream\UnixAddress;

/**
 * @param resource $resource
 *
 * @throws SocketException
 */
function fromResourcePeer($resource): SocketAddress
{
    $name = Internal\getStreamSocketName($resource, true);

    /** @psalm-suppress TypeDoesNotContainType */
    if ($name === false || $name === "\0") {
        return fromResourceLocal($resource);
    }

    return fromString($name);
}

/**
 * @param resource $resource
 *
 * @throws SocketException
 */
function fromResourceLocal($resource): SocketAddress
{
    $wantPeer = false;

    do {
        $name = Internal\getStreamSocketName($resource, $wantPeer);

        /** @psalm-suppress RedundantCondition */
        if ($name !== false && $name !== "\0") {
            return fromString($name);
        }
    } while ($wantPeer = !$wantPeer);

    return new UnixAddress('');
}

/**
 * @throws SocketException
 */
function fromString(string $name): SocketAddress
{
    if (\preg_match("/\\[(?P<ip>[0-9a-f:]+)](:(?P<port>\\d+))$/", $name, $match)) {
        /** @psalm-suppress ArgumentTypeCoercion */
        return new InternetAddress($match['ip'], (int) $match['port']);
    }

    if (\preg_match("/(?P<ip>\\d+\\.\\d+\\.\\d+\\.\\d+)(:(?P<port>\\d+))$/", $name, $match)) {
        /** @psalm-suppress ArgumentTypeCoercion */
        return new InternetAddress($match['ip'], (int) $match['port']);
    }

    return new UnixAddress($name);
}
