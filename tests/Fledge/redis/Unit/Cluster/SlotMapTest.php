<?php

use Fledge\Async\Redis\Cluster\SlotMap;

function clusterSlotsReply(): array
{
    return [
        [0, 5460, ['127.0.0.1', 17000, 'node-1'], ['127.0.0.1', 17003, 'node-1-replica']],
        [5461, 10922, ['127.0.0.1', 17001, 'node-2']],
        [10923, 16383, ['127.0.0.1', 17002, 'node-3'], ['127.0.0.1', 17005, 'node-3-replica']],
    ];
}

it('builds masters and slot lookup from a CLUSTER SLOTS reply', function () {
    $map = SlotMap::fromClusterSlots(clusterSlotsReply());

    expect($map->masters())->toBe(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002'])
        ->and($map->nodeForSlot(0))->toBe('127.0.0.1:17000')
        ->and($map->nodeForSlot(5460))->toBe('127.0.0.1:17000')
        ->and($map->nodeForSlot(5461))->toBe('127.0.0.1:17001')
        ->and($map->nodeForSlot(10922))->toBe('127.0.0.1:17001')
        ->and($map->nodeForSlot(10923))->toBe('127.0.0.1:17002')
        ->and($map->nodeForSlot(16383))->toBe('127.0.0.1:17002');
});

it('records replicas per master', function () {
    $map = SlotMap::fromClusterSlots(clusterSlotsReply());

    expect($map->replicasOf('127.0.0.1:17000'))->toBe(['127.0.0.1:17003'])
        ->and($map->replicasOf('127.0.0.1:17001'))->toBe([])
        ->and($map->replicasOf('127.0.0.1:17002'))->toBe(['127.0.0.1:17005']);
});

it('reports an empty map before refresh', function () {
    $map = new SlotMap([], [], []);

    expect($map->isEmpty())->toBeTrue()
        ->and($map->masters())->toBe([]);
});

it('rejects out-of-range slots', function () {
    $map = SlotMap::fromClusterSlots(clusterSlotsReply());

    $map->nodeForSlot(16384);
})->throws(InvalidArgumentException::class);

it('wraps IPv6 endpoints in brackets', function () {
    $map = SlotMap::fromClusterSlots([
        [0, 16383, ['::1', 6379, 'ipv6-node']],
    ]);

    expect($map->masters())->toBe(['[::1]:6379']);
});
