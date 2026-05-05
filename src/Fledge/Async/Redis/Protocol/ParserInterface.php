<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Protocol;

/**
 * Minimal contract that any RESP parser plugged into SocketRedisConnection
 * must satisfy. The default implementation is RespParser. Alternative parsers
 * (for example a C-level extension adapter) are passed in per connection via
 * the optional parser factory closure on SocketRedisConnection or
 * SocketRedisConnector.
 */
interface ParserInterface
{
    /**
     * Feed bytes off the wire. Each complete reply produced by the parser
     * is delivered to the push closure passed at construction time.
     */
    public function push(string $data): void;

    /**
     * Stop accepting input and release any pending state.
     */
    public function cancel(): void;
}
