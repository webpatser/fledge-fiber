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
     * Oldest extension release safe to use. Versions before 0.1.4 corrupt the
     * parser state machine on RESP2 nulls nested inside aggregates ($-1 / *-1
     * as array elements, e.g. XPENDING summaries and MGET with missing keys),
     * failing every such reply on the connection.
     */
    public const string MINIMUM_VERSION = '0.1.4';

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
                    'resp3 extension %s is older than %s, which fixes a wire-corruption bug on nested RESP2 nulls; '
                    . 'falling back to the pure-PHP RESP parser. Upgrade via `pie install webpatser/php-resp3`.',
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
