<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\RedisException;

final class ClusterTopology
{
    private SlotMap $slotMap;

    public function __construct()
    {
        $this->slotMap = new SlotMap([], [], []);
    }

    public function slotMap(): SlotMap
    {
        return $this->slotMap;
    }

    public function isStale(): bool
    {
        return $this->slotMap->isEmpty();
    }

    /**
     * Run CLUSTER SLOTS against the given seed link and replace the current slot map.
     */
    public function refresh(RedisLink $seed): void
    {
        $reply = $seed->execute('CLUSTER', ['SLOTS'])->unwrap();

        if (!\is_array($reply)) {
            throw new RedisException('CLUSTER SLOTS returned a non-array reply.');
        }

        /** @var list<array<int, mixed>> $rows */
        $rows = $reply;

        $this->slotMap = SlotMap::fromClusterSlots($rows);

        if ($this->slotMap->isEmpty()) {
            throw new RedisException('CLUSTER SLOTS returned an empty topology.');
        }
    }
}
