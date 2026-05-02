<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

final class SlotMap
{
    /**
     * @param  array<int, string>  $slotToMaster  flat array: slot index → "host:port"
     * @param  list<string>  $masters
     * @param  array<string, list<string>>  $replicasByMaster  master endpoint → list of replica endpoints
     */
    public function __construct(
        private readonly array $slotToMaster,
        private readonly array $masters,
        private readonly array $replicasByMaster,
    ) {
    }

    /**
     * Build a SlotMap from a parsed CLUSTER SLOTS reply.
     *
     * Each row: [start_slot, end_slot, [host, port, id, ...meta], [replica_host, replica_port, ...], ...]
     *
     * @param  list<array<int, mixed>>  $reply
     */
    public static function fromClusterSlots(array $reply): self
    {
        $slotToMaster = \array_fill(0, SlotHasher::SLOT_COUNT, '');
        $masters = [];
        $replicasByMaster = [];

        foreach ($reply as $row) {
            if (!\is_array($row) || \count($row) < 3) {
                continue;
            }

            $start = (int) $row[0];
            $end = (int) $row[1];
            $masterNode = $row[2];

            if (!\is_array($masterNode) || \count($masterNode) < 2) {
                continue;
            }

            $masterEndpoint = self::endpoint((string) $masterNode[0], (int) $masterNode[1]);

            if (!\in_array($masterEndpoint, $masters, true)) {
                $masters[] = $masterEndpoint;
            }

            for ($slot = $start; $slot <= $end && $slot < SlotHasher::SLOT_COUNT; $slot++) {
                $slotToMaster[$slot] = $masterEndpoint;
            }

            $replicas = [];
            for ($i = 3; $i < \count($row); $i++) {
                $replica = $row[$i];
                if (\is_array($replica) && \count($replica) >= 2) {
                    $replicas[] = self::endpoint((string) $replica[0], (int) $replica[1]);
                }
            }

            $replicasByMaster[$masterEndpoint] = \array_merge($replicasByMaster[$masterEndpoint] ?? [], $replicas);
        }

        return new self($slotToMaster, $masters, $replicasByMaster);
    }

    public function nodeForSlot(int $slot): string
    {
        if ($slot < 0 || $slot >= SlotHasher::SLOT_COUNT) {
            throw new \InvalidArgumentException(\sprintf('Slot %d out of range [0, %d).', $slot, SlotHasher::SLOT_COUNT));
        }

        $node = $this->slotToMaster[$slot] ?? '';

        if ($node === '') {
            if ($this->masters === []) {
                throw new \RuntimeException('Slot map is empty; topology has not been refreshed.');
            }

            return $this->masters[0];
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    public function masters(): array
    {
        return $this->masters;
    }

    /**
     * @return list<string>
     */
    public function replicasOf(string $master): array
    {
        return $this->replicasByMaster[$master] ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->masters === [];
    }

    public function randomMaster(): string
    {
        if ($this->masters === []) {
            throw new \RuntimeException('Slot map is empty; topology has not been refreshed.');
        }

        return $this->masters[\array_rand($this->masters)];
    }

    private static function endpoint(string $host, int $port): string
    {
        if (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            return '['.$host.']:'.$port;
        }

        return $host.':'.$port;
    }
}
