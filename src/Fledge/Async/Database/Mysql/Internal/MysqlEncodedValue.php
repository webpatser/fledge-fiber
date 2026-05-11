<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

use Fledge\Async\Database\Mysql\MysqlDataType;

/** @internal */
final readonly class MysqlEncodedValue
{
    public static function fromValue(mixed $param, ?MysqlDataType $targetType = null): self
    {
        if ($param === null) {
            return new self(MysqlDataType::Null, "");
        }

        if ($param instanceof \BackedEnum) {
            return self::fromValue($param->value, $targetType);
        }

        if ($param instanceof \Stringable) {
            $param = (string) $param;
        }

        return match (true) {
            \is_string($param) => new self(
                self::stringTypeFor($targetType),
                MysqlDataType::encodeInt(\strlen($param)) . $param,
            ),
            \is_bool($param)  => new self(MysqlDataType::Tiny, $param ? "\x01" : "\0"),
            \is_int($param)   => self::fromInt($param),
            \is_float($param) => new self(MysqlDataType::Double, \pack("e", $param)),
            default           => throw new \TypeError(
                "Unexpected type for query parameter: " . \get_debug_type($param),
            ),
        };
    }

    public static function stringTypeFor(?MysqlDataType $targetType): MysqlDataType
    {
        return match ($targetType) {
            MysqlDataType::TinyBlob,
            MysqlDataType::Blob,
            MysqlDataType::MediumBlob,
            MysqlDataType::LongBlob => MysqlDataType::LongBlob,
            MysqlDataType::Json     => MysqlDataType::Json,
            default                 => MysqlDataType::VarString,
        };
    }

    private static function fromInt(int $param): self
    {
        return match (true) {
            $param >= -(1 << 7)  && $param < (1 << 7)  => new self(MysqlDataType::Tiny,     MysqlDataType::encodeInt8($param)),
            $param >= -(1 << 15) && $param < (1 << 15) => new self(MysqlDataType::Short,    MysqlDataType::encodeInt16($param)),
            $param >= -(1 << 31) && $param < (1 << 31) => new self(MysqlDataType::Long,     MysqlDataType::encodeInt32($param)),
            default                                    => new self(MysqlDataType::LongLong, MysqlDataType::encodeInt64($param)),
        };
    }

    private function __construct(
        private MysqlDataType $type,
        private string $bytes,
    ) {
    }

    public function getType(): MysqlDataType
    {
        return $this->type;
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }
}
