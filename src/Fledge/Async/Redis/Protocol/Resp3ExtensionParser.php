<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Protocol;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/**
 * Drop-in replacement for RespParser that delegates parsing to the
 * webpatser/php-resp3 PECL extension (\Resp3\Parser) when it is loaded.
 *
 * Constructor signature matches RespParser so it slots in via the
 * SocketRedisConnection $parserFactory injection point. Output values are
 * wrapped in RedisValue / RedisError so the rest of the pipeline sees
 * identical types.
 *
 * RESP3 wrapper objects (VerbatimString, PushMessage) are unwrapped to their
 * underlying scalar / array because the existing pipeline only consumes
 * RESP2-shaped values; this keeps the parser behavior compatible with
 * RespParser while still benefiting from the C-level parsing speed.
 *
 * Activation: only used when extension_loaded('resp3') returns true. The
 * default factory in SocketRedisConnection picks this class automatically;
 * otherwise it falls back to the pure-PHP RespParser.
 */
final class Resp3ExtensionParser implements ParserInterface
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly \Resp3\Parser $parser;

    /**
     * @psalm-param \Closure(RedisResponse):void $push
     */
    public function __construct(private readonly \Closure $push)
    {
        $this->parser = new \Resp3\Parser();
    }

    public function push(string $data): void
    {
        $this->parser->feed($data);

        while ($this->parser->hasNext()) {
            $value = $this->parser->next();

            if ($value instanceof \Resp3\RedisException) {
                ($this->push)(new RedisError($value->getMessage()));
                continue;
            }

            if ($value instanceof \Resp3\VerbatimString) {
                $value = $value->value;
            } elseif ($value instanceof \Resp3\PushMessage) {
                $value = $value->payload;
            }

            ($this->push)(new RedisValue($value));
        }
    }

    public function cancel(): void
    {
        $this->parser->reset();
    }
}
