<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Protocol;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Stream\Parser;

/**
 * @psalm-type ParserGeneratorType = \Generator<int, int|string, string, RedisResponse>
 */
final class RespParser implements ParserInterface
{
    use ForbidCloning;
    use ForbidSerialization;

    private const CRLF = "\r\n";

    private const TYPE_SIMPLE_STRING = '+';
    private const TYPE_ERROR = '-';
    private const TYPE_ARRAY = '*';
    private const TYPE_BULK_STRING = '$';
    private const TYPE_INTEGER = ':';

    /** Maximum bulk string payload in bytes (matches php-resp3 default). */
    public const MAX_BULK_LENGTH = 536_870_912;

    /** Maximum number of elements in one aggregate (matches php-resp3 default). */
    public const MAX_AGGREGATE_COUNT = 1_000_000;

    /** Maximum aggregate nesting depth (matches php-resp3 default). */
    public const MAX_DEPTH = 100;

    /** Maximum length of an unterminated line (type byte payload up to CRLF). */
    public const MAX_LINE_LENGTH = 1_048_576;

    private readonly Parser $parser;

    /**
     * @psalm-param \Closure(RedisResponse):void $push
     */
    public function __construct(\Closure $push)
    {
        $this->parser = new Parser(
            self::parser($push),
            self::MAX_LINE_LENGTH,
            static fn (int $max): ProtocolException => new ProtocolException(
                'Line exceeds maximum length of ' . $max . ' bytes without terminator'
            ),
        );
    }

    public function push(string $data): void
    {
        $this->parser->push($data);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    /**
     * @param \Closure(RedisResponse):void $push
     *
     * @return \Generator<int, int|string, string, void>
     */
    private static function parser(\Closure $push): \Generator
    {
        while (true) {
            $push(yield from self::parseValue(yield 1, yield self::CRLF));
        }
    }

    /**
     * @psalm-return ParserGeneratorType
     */
    private static function parseValue(string $type, string $payload, int $depth = 0): \Generator
    {
        return match ($type) {
            self::TYPE_SIMPLE_STRING => new RedisValue($payload),
            self::TYPE_INTEGER => new RedisValue((int) $payload),
            self::TYPE_BULK_STRING => yield from self::parseString((int) $payload),
            self::TYPE_ARRAY => yield from self::parseArray((int) $payload, $depth),
            self::TYPE_ERROR => new RedisError($payload),
            default => throw new ProtocolException('Unknown resp data type: ' . $type),
        };
    }

    /**
     * @psalm-return ParserGeneratorType
     */
    private static function parseString(int $length): \Generator
    {
        if ($length < -1) {
            throw new ProtocolException('Invalid string length: ' . $length);
        }

        if ($length === -1) {
            return new RedisValue(null);
        }

        if ($length > self::MAX_BULK_LENGTH) {
            throw new ProtocolException('Bulk string length ' . $length . ' exceeds maximum of ' . self::MAX_BULK_LENGTH);
        }

        $payload = match ($length) {
            0 => '',
            default => yield $length,
        };

        yield 2; // Remove trailing CRLF

        return new RedisValue($payload);
    }

    /**
     * @psalm-return ParserGeneratorType
     */
    private static function parseArray(int $count, int $depth): \Generator
    {
        if ($count < -1) {
            throw new ProtocolException('Invalid array length: ' . $count);
        }

        if ($count === -1) {
            return new RedisValue(null);
        }

        if ($count > self::MAX_AGGREGATE_COUNT) {
            throw new ProtocolException('Array length ' . $count . ' exceeds maximum of ' . self::MAX_AGGREGATE_COUNT);
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new ProtocolException('Array nesting exceeds maximum depth of ' . self::MAX_DEPTH);
        }

        $payload = [];
        for ($i = 0; $i < $count; $i++) {
            $payload[] = (yield from self::parseValue(yield 1, yield self::CRLF, $depth + 1))->unwrap();
        }

        return new RedisValue($payload);
    }
}
