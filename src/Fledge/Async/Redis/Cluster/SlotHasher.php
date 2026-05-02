<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

final class SlotHasher
{
    public const SLOT_COUNT = 16384;

    /** @var array<int, int>|null */
    private static ?array $table = null;

    public static function slotFor(string $key): int
    {
        $hashed = self::hashTag($key);

        return self::crc16($hashed) % self::SLOT_COUNT;
    }

    private static function hashTag(string $key): string
    {
        $start = \strpos($key, '{');

        if ($start === false) {
            return $key;
        }

        $end = \strpos($key, '}', $start + 1);

        if ($end === false || $end === $start + 1) {
            return $key;
        }

        return \substr($key, $start + 1, $end - $start - 1);
    }

    private static function crc16(string $data): int
    {
        $table = self::$table ??= self::buildTable();

        $crc = 0;
        $length = \strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc = (($crc << 8) & 0xFFFF) ^ $table[(($crc >> 8) ^ \ord($data[$i])) & 0xFF];
        }

        return $crc;
    }

    /**
     * @return array<int, int>
     */
    private static function buildTable(): array
    {
        $table = [];

        for ($i = 0; $i < 256; $i++) {
            $crc = $i << 8;

            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }

            $table[$i] = $crc;
        }

        return $table;
    }
}
