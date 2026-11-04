<?php

use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Pdo\FledgePdo;
use Revolt\EventLoop;

/**
 * Prepared statements with thousands of placeholders against real MySQL and
 * MariaDB. Every query returns a single column and each statement is executed
 * once. The needle sits at the very end of the list, so a truncated or
 * ignored parameter list yields the wrong answer.
 */
function longListPdo(string $flavor): FledgePdo
{
    $config = $flavor === 'mariadb' ? mariadbConfig() : mysqlConfig();

    return (new FledgeMySqlConnector)->connect(['pool_size' => 1] + $config);
}

function longListSkipUnless(string $flavor, object $test): void
{
    $available = $flavor === 'mariadb' ? mariadbAvailable() : mysqlAvailable();

    if (! $available) {
        $test->markTestSkipped(ucfirst($flavor).' not available');
    }
}

/**
 * Run `SELECT ? IN (?, ...)` once and return the single scalar.
 *
 * @param  list<int|string>  $list
 */
function longListInQuery(FledgePdo $pdo, int|string $needle, array $list): int
{
    $watchdog = EventLoop::delay(30, static fn () => $pdo->close());

    try {
        $placeholders = implode(', ', array_fill(0, count($list), '?'));
        $stmt = $pdo->prepare("SELECT ? IN ({$placeholders}) AS found");
        $stmt->execute([$needle, ...$list]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        expect($rows)->toHaveCount(1);

        return (int) $rows[0]['found'];
    } finally {
        EventLoop::cancel($watchdog);
        $pdo->close();
    }
}

function longListUuids(int $count): array
{
    $uuids = [];

    for ($i = 1; $i <= $count; $i++) {
        $uuids[] = sprintf('%08x-0000-4000-8000-%012x', $i, $i * 7);
    }

    return $uuids;
}

foreach (['mysql', 'mariadb'] as $flavor) {
    it("finds the last of 5000 integer bindings plus a string on {$flavor}", function () use ($flavor) {
        longListSkipUnless($flavor, $this);

        $list = range(1, 5000);
        $list[] = 'needle-string';

        expect(longListInQuery(longListPdo($flavor), 'needle-string', $list))->toBe(1);
    });

    it("finds the last integer of 5000 bindings on {$flavor}", function () use ($flavor) {
        longListSkipUnless($flavor, $this);

        $list = range(1, 5000);
        $list[] = 'needle-string';

        expect(longListInQuery(longListPdo($flavor), 5000, $list))->toBe(1);
    });

    it("reports a missing value in 5000 integer bindings on {$flavor}", function () use ($flavor) {
        longListSkipUnless($flavor, $this);

        $list = range(1, 5000);
        $list[] = 'needle-string';

        expect(longListInQuery(longListPdo($flavor), 5001, $list))->toBe(0);
    });

    it("finds the last of 2813 uuids on {$flavor}", function () use ($flavor) {
        longListSkipUnless($flavor, $this);

        $uuids = longListUuids(2813);

        expect(longListInQuery(longListPdo($flavor), end($uuids), $uuids))->toBe(1);
    });

    it("reports a missing uuid in 2813 uuids on {$flavor}", function () use ($flavor) {
        longListSkipUnless($flavor, $this);

        $uuids = longListUuids(2813);
        $missing = longListUuids(2814)[2813];

        expect(longListInQuery(longListPdo($flavor), $missing, $uuids))->toBe(0);
    });
}
