<?php

use Fledge\Async\Redis\Cluster\SlotHasher;

it('hashes published Redis vectors to documented slots', function () {
    expect(SlotHasher::slotFor('foo'))->toBe(12182)
        ->and(SlotHasher::slotFor('bar'))->toBe(5061);
});

it('keeps hash-tagged keys on the same slot', function () {
    $a = SlotHasher::slotFor('{user1000}.following');
    $b = SlotHasher::slotFor('{user1000}.followers');

    expect($a)->toBe($b);
});

it('treats empty hash tags as part of the key', function () {
    $emptyTag = SlotHasher::slotFor('{}.foo');
    $rawKey = SlotHasher::slotFor('foo');

    expect($emptyTag)->not->toBe($rawKey);
});

it('falls back to the full key when no closing brace is present', function () {
    $unclosed = SlotHasher::slotFor('{foo');
    $explicit = SlotHasher::slotFor('{foo');

    expect($unclosed)->toBe($explicit);
});

it('hashes the empty string to slot 0', function () {
    expect(SlotHasher::slotFor(''))->toBe(0);
});

it('always returns a slot inside the 0..16383 range', function () {
    foreach (['key1', 'key2', '🚀', "\xff\xff\xff", str_repeat('x', 1024)] as $key) {
        $slot = SlotHasher::slotFor($key);

        expect($slot)->toBeGreaterThanOrEqual(0)->toBeLessThan(SlotHasher::SLOT_COUNT);
    }
});

it('only hashes the first balanced tag pair', function () {
    $first = SlotHasher::slotFor('{tag1}{tag2}.x');
    $rawTag1 = SlotHasher::slotFor('tag1');

    expect($first)->toBe($rawTag1);
});
