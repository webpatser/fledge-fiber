<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

use Fledge\Async\Closable;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\RedisException;

/**
 * A RedisConnection allows sending and receiving values, but does not contain any reconnect logic or linking responses
 * to requests.
 */
interface RedisConnection extends Closable
{
    /**
     * @throws RedisException If reading from the connection fails.
     */
    public function receive(): ?RedisResponse;

    /**
     * @throws RedisException If writing to the connection fails.
     */
    public function send(string ...$args): void;

    /**
     * @return string A name for debugging purposes, e.g. the connect URI.
     */
    public function getName(): string;

    public function reference(): void;

    public function unreference(): void;
}
