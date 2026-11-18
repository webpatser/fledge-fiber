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
 * Activation: only used when isUsable() returns true, i.e. the resp3
 * extension is loaded AND at least MINIMUM_VERSION. The default factory in
 * SocketRedisConnection picks this class automatically; otherwise it falls
 * back to the pure-PHP RespParser.
 */
final class Resp3ExtensionParser implements ParserInterface
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * Oldest extension release safe to use. Before 0.2.0 only top-level error
     * replies became \Resp3\RedisException; an error nested in an aggregate
     * (one failed command in an EXEC reply, redis.error_reply in an EVAL
     * table) came back as a plain string, indistinguishable from a value.
     * That turns a failed command into a success, for example a failed SELECT
     * queued in MULTI that the database tracking would then record. Versions
     * before 0.1.4 also corrupt the parser state machine on RESP2 nulls nested
     * inside aggregates.
     */
    public const string MINIMUM_VERSION = '0.2.0';

    private static bool $warnedOutdated = false;

    private readonly \Resp3\Parser $parser;

    /**
     * Whether the loaded resp3 extension can be used as the wire parser.
     *
     * False when the extension is absent or older than MINIMUM_VERSION; the
     * caller should fall back to the pure-PHP RespParser. An outdated
     * extension triggers a once-per-process E_USER_WARNING so operators know
     * the C parser is being bypassed and why.
     */
    public static function isUsable(): bool
    {
        if (!\extension_loaded('resp3') || !\class_exists(\Resp3\Parser::class, false)) {
            return false;
        }

        $version = \phpversion('resp3') ?: null;

        if (self::versionIsSupported($version)) {
            return true;
        }

        if (!self::$warnedOutdated) {
            self::$warnedOutdated = true;
            \trigger_error(
                \sprintf(
                    'resp3 extension %s is older than %s, which returns nested error replies as errors instead of '
                    . 'plain strings; falling back to the pure-PHP RESP parser. Upgrade via '
                    . '`pie install webpatser/php-resp3:^0.2`.',
                    $version ?? 'unknown',
                    self::MINIMUM_VERSION,
                ),
                \E_USER_WARNING,
            );
        }

        return false;
    }

    /**
     * Pure version gate, split out for tests.
     */
    public static function versionIsSupported(?string $version): bool
    {
        return $version !== null && \version_compare($version, self::MINIMUM_VERSION, '>=');
    }

    /**
     * @psalm-param \Closure(RedisResponse):void $push
     */
    public function __construct(private readonly \Closure $push)
    {
        // An injected parser factory must not bypass the gate: an older
        // extension hands nested errors back as values.
        if (!self::versionIsSupported(\phpversion('resp3') ?: null)) {
            throw new \LogicException(\sprintf(
                'Resp3ExtensionParser needs the resp3 extension %s or newer; use RespParser instead.',
                self::MINIMUM_VERSION,
            ));
        }

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

            // Same shape as RespParser: errors nested in an aggregate become
            // QueryException values in their position instead of throwing.
            // Since 0.2.0 nested verbatim strings are wrapped too; unwrap them
            // like the top-level ones.
            if (\is_array($value)) {
                \array_walk_recursive($value, static function (mixed &$item): void {
                    if ($item instanceof \Resp3\RedisException) {
                        $item = new QueryException($item->getMessage());
                    } elseif ($item instanceof \Resp3\VerbatimString) {
                        $item = $item->value;
                    }
                });
            }

            ($this->push)(new RedisValue($value));
        }
    }

    public function cancel(): void
    {
        $this->parser->reset();
    }
}
