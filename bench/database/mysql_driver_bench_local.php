<?php

declare(strict_types=1);

/**
 * Local three-way MySQL driver benchmark at raw PDO level (SELECT only).
 *
 * Usage: php -d extension=<path>/fiberio.so mysql_driver_bench_local.php
 *        [--host=127.0.0.1] [--port=13307] [--user=fledge] [--password=fledge]
 *        [--database=fledge_test] [--runs=5] [--scale=0.2]
 *        [--revolt-waiter=~/Development/Github/php-fiberio/src/RevoltWaiter.php]
 *
 * Local sibling of mysql_driver_bench.php: no Laravel app, fledge-fiber's own
 * vendor autoload, tables from seed_local_mariadb.php in the docker mariadb:11.
 * All three columns use TCP to the same host:port and credentials:
 *   - "fledge"            : FledgeMySqlPdo on a MysqlConnectionPool (32 connections)
 *   - "pdo_mysql"         : one stock PDO; in the fiber cases its blocking I/O
 *                           serialises the fibers
 *   - "pdo_mysql+fiberio" : FiberIo\enable(new FiberIo\RevoltWaiter) and a pool
 *                           of 32 real PDO connections, one per fiber. Bulk and
 *                           point cases run inside one fiber so the waiter path
 *                           is the one measured.
 *
 * The hook is enabled only while the fiberio column runs (its PDOs are opened
 * while enabled), so fledge's own sockets never go through fiberio.
 */

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

const TABLES = [
    // label => [table, pk, rows at scale 1.0]
    'tk_raw_json' => ['tk_raw_json', 'id', 10_000],
    'koop_document_metadata' => ['koop_document_metadata', 'id', 100_000],
    'feed_items' => ['feed_items', 'id', 50_000],
];
const POINT_QUERIES = 5_000;
const FIBERS = 32;
const QUERIES_PER_FIBER = 10;
const RANGE_ROWS = 100;
const SLEEP_SECONDS = 0.05;
const WARMUPS = 1;

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

// ---------------------------------------------------------------- arguments
$opt = [
    'host' => '127.0.0.1', 'port' => '13307', 'user' => 'fledge', 'password' => 'fledge', 'database' => 'fledge_test',
    'runs' => '5', 'scale' => '0.2',
    'revolt-waiter' => (getenv('HOME') ?: '') . '/Development/Github/php-fiberio/src/RevoltWaiter.php',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        fail("unknown argument {$arg}");
    }
}
$runs = max(1, (int) $opt['runs']);
$scale = max(0.0001, (float) $opt['scale']);

if (! in_array($opt['host'], ['127.0.0.1', '::1'], true)) {
    fail('host must be a loopback address (TCP), got ' . $opt['host']);
}
if (! extension_loaded('fiberio')) {
    fail('fiberio is not loaded; run with php -d extension=<path>/modules/fiberio.so');
}
if (! is_file($opt['revolt-waiter'])) {
    fail("RevoltWaiter not found at {$opt['revolt-waiter']}");
}

require __DIR__ . '/../../vendor/autoload.php';
require $opt['revolt-waiter'];

// ---------------------------------------------------------------- connections
$dsn = "mysql:host={$opt['host']};port={$opt['port']};dbname={$opt['database']};charset=utf8mb4";
// Laravel's default PDO options for the mysql/mariadb drivers.
$pdoOptions = [
    PDO::ATTR_CASE => PDO::CASE_NATURAL,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
    PDO::ATTR_STRINGIFY_FETCHES => false,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$newPdo = fn (): PDO => new PDO($dsn, $opt['user'], $opt['password'], $pdoOptions);

if (FiberIo\enabled()) {
    FiberIo\disable();
}

$fledge = new FledgeMySqlPdo(new MysqlConnectionPool(
    new MysqlConfig(
        host: $opt['host'],
        port: (int) $opt['port'],
        user: $opt['user'],
        password: $opt['password'],
        database: $opt['database'],
        charset: 'utf8mb4',
        collate: 'utf8mb4_unicode_ci',
    ),
    FIBERS,
));
$stock = $newPdo();

$waiter = new FiberIo\RevoltWaiter;
FiberIo\enable($waiter);
$fiberPool = [];
for ($i = 0; $i < FIBERS; $i++) {
    $fiberPool[] = $newPdo();
}
FiberIo\disable();

$version = (string) $stock->query('select version()')->fetchColumn();
$stockStatus = (string) $stock->getAttribute(PDO::ATTR_CONNECTION_STATUS);
$fiberStatus = (string) $fiberPool[0]->getAttribute(PDO::ATTR_CONNECTION_STATUS);
$st = $fledge->prepare('select version() as v');
$st->execute();
$fledgeVersion = (string) $st->fetchAll(PDO::FETCH_ASSOC)[0]['v'];
if (! str_contains($stockStatus, 'TCP/IP') || ! str_contains($fiberStatus, 'TCP/IP')) {
    fail("pdo_mysql is not on TCP: {$stockStatus} / {$fiberStatus}");
}
if ($fledgeVersion !== $version) {
    fail("fledge and pdo_mysql reached different servers: {$fledgeVersion} vs {$version}");
}

echo "Transport check\n";
echo "  fledge-mariadb    : tcp://{$opt['host']}:{$opt['port']} (FledgeMySqlPdo, pool " . FIBERS . ")\n";
echo "  pdo_mysql         : {$stockStatus}, port {$opt['port']}\n";
echo "  pdo_mysql+fiberio : {$fiberStatus}, port {$opt['port']}, " . FIBERS . " PDO connections, RevoltWaiter\n";
echo "  server            : {$version}\n";
echo '  php               : ' . PHP_VERSION . ', fiberio ' . phpversion('fiberio') . ", runs={$runs}, scale={$scale}\n\n";

// ---------------------------------------------------------------- helpers
function scaled(int $n, float $scale): int
{
    return max(1, (int) round($n * $scale));
}

function median(array $v): float
{
    sort($v);
    $c = count($v);

    return $c % 2 ? (float) $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2;
}

/** Run one measured iteration: wall seconds, cpu seconds (user+sys), peak memory bytes above baseline, rows. */
function measure(Closure $fn): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $mem0 = memory_get_usage();
    $ru0 = getrusage();
    $t0 = hrtime(true);
    $rows = $fn();
    $wall = (hrtime(true) - $t0) / 1e9;
    $ru1 = getrusage();
    $peak = memory_get_peak_usage() - $mem0;
    $cpu = ($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e6
        + ($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1e6;

    return [$wall, $cpu, max(0, $peak), (int) $rows];
}

function bench(Closure $fn, int $runs): array
{
    for ($i = 0; $i < WARMUPS; $i++) {
        $fn();
    }
    $w = $c = $m = [];
    $rows = 0;
    for ($i = 0; $i < $runs; $i++) {
        [$w[], $c[], $m[], $rows] = measure($fn);
    }

    return [
        'wall_s' => median($w),
        'cpu_s' => median($c),
        'peak_mem_mb' => median($m) / 1048576,
        'rows' => $rows,
        'cpu_us_per_row' => $rows > 0 ? median($c) / $rows * 1e6 : null,
    ];
}

function rows(object $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function randomStart(int $lo, int $hi, int $reserve = 0): int
{
    return mt_rand($lo, max($lo, $hi - $reserve));
}

mt_srand(20261007);

// ---------------------------------------------------------------- plan
// Same start keys for every driver so they read identical rows.
$plan = [];
foreach (TABLES as $label => [$table, $pk, $rowsWanted]) {
    $b = rows($stock, "select min(`{$pk}`) as lo, max(`{$pk}`) as hi, count(*) as n from `{$table}`")[0];
    $rowsN = scaled($rowsWanted, $scale);
    if ((int) $b['n'] < $rowsN) {
        fail("table {$table} has {$b['n']} rows, needs at least {$rowsN}; run seed_local_mariadb.php");
    }
    if (is_numeric($b['lo'])) {
        $start = randomStart((int) $b['lo'], (int) $b['hi'], $rowsN * 2);
    } else {
        // String PK (char(36) uuid): seeded hex prefix (first nibble 0-7), resolved to a real key.
        $prefix = dechex(mt_rand(0, 7)) . sprintf('%06x', mt_rand(0, 0xFFFFFF));
        $start = rows($stock, "select `{$pk}` as k from `{$table}` where `{$pk}` >= ? order by `{$pk}` limit 1", [$prefix])[0]['k'] ?? $b['lo'];
    }
    $plan[$label] = ['table' => $table, 'pk' => $pk, 'rows' => $rowsN, 'start' => $start, 'lo' => $b['lo'], 'hi' => $b['hi']];
}

[$ft, $fpk] = TABLES['feed_items'];
$pointN = scaled(POINT_QUERIES, $scale);
$sampleStart = randomStart((int) $plan['feed_items']['lo'], (int) $plan['feed_items']['hi'], $pointN * 2);
$pointIds = array_map(
    fn ($r) => (int) $r['id'],
    rows($stock, "select `{$fpk}` as id from `{$ft}` where `{$fpk}` >= ? order by `{$fpk}` limit {$pointN}", [$sampleStart]),
);
shuffle($pointIds);

$fibers = FIBERS;
$perFiber = scaled(QUERIES_PER_FIBER, max($scale, 0.1));
$fiberStarts = [];
for ($f = 0; $f < $fibers; $f++) {
    for ($q = 0; $q < $perFiber; $q++) {
        $fiberStarts[$f][] = randomStart((int) $plan['feed_items']['lo'], (int) $plan['feed_items']['hi'], RANGE_ROWS * 2);
    }
}

// ---------------------------------------------------------------- drivers
// conn(f): the PDO-like object fiber f uses; single(fn): how a non-concurrent case runs.
$direct = fn (Closure $fn) => $fn;
$inFiber = fn (Closure $fn) => fn () => async($fn)->await();
$drivers = [
    'fledge' => ['conn' => fn (int $f) => $fledge, 'single' => $direct, 'hook' => false],
    'pdo_mysql' => ['conn' => fn (int $f) => $stock, 'single' => $direct, 'hook' => false],
    'pdo_mysql+fiberio' => ['conn' => fn (int $f) => $fiberPool[$f], 'single' => $inFiber, 'hook' => true],
];

// ---------------------------------------------------------------- cases
/** @var array<string, Closure(array): Closure> $cases case => factory(driver) */
$cases = [];

foreach ($plan as $label => $p) {
    $sql = "select * from `{$p['table']}` where `{$p['pk']}` >= ? order by `{$p['pk']}` limit {$p['rows']}";
    $cases["bulk {$label} ({$p['rows']}) raw PDO"] = fn (array $d) => $d['single'](
        fn () => count(rows($d['conn'](0), $sql, [$p['start']])),
    );
}

$pointSql = "select * from `{$ft}` where `{$fpk}` = ? limit 1";
$cases["point feed_items ({$pointN}) raw PDO"] = fn (array $d) => $d['single'](function () use ($d, $pointSql, $pointIds) {
    $n = 0;
    $st = $d['conn'](0)->prepare($pointSql);
    foreach ($pointIds as $id) {
        $st->execute([$id]);
        $n += count($st->fetchAll(PDO::FETCH_ASSOC));
    }

    return $n;
});

$rangeSql = "select * from `{$ft}` where `{$fpk}` >= ? order by `{$fpk}` limit " . RANGE_ROWS;
$cases["concurrency {$fibers} fibers x {$perFiber} range queries (" . RANGE_ROWS . ' rows)'] = fn (array $d) => function () use ($d, $fibers, $fiberStarts, $rangeSql) {
    $futures = [];
    for ($f = 0; $f < $fibers; $f++) {
        $futures[] = async(function () use ($d, $f, $fiberStarts, $rangeSql) {
            $n = 0;
            foreach ($fiberStarts[$f] as $start) {
                $n += count(rows($d['conn']($f), $rangeSql, [$start]));
            }

            return $n;
        });
    }

    return array_sum(await($futures));
};

$cases["concurrency {$fibers} fibers x SELECT SLEEP(" . SLEEP_SECONDS . ')'] = fn (array $d) => function () use ($d, $fibers) {
    $futures = [];
    for ($f = 0; $f < $fibers; $f++) {
        $futures[] = async(fn () => count(rows($d['conn']($f), 'select sleep(' . SLEEP_SECONDS . ') as s')));
    }

    return array_sum(await($futures));
};

// ---------------------------------------------------------------- run
$results = [];
foreach ($cases as $name => $factory) {
    fwrite(STDERR, "running: {$name}\n");
    foreach ($drivers as $driver => $d) {
        if ($d['hook']) {
            FiberIo\enable($waiter);
        }
        try {
            $results[$name][$driver] = bench($factory($d), $runs);
        } finally {
            if ($d['hook']) {
                FiberIo\disable();
            }
        }
    }
}

// ---------------------------------------------------------------- output
$fmt = fn (?float $v, int $d = 3) => $v === null ? '-' : number_format($v, $d, '.', '');

echo "| case | driver | rows | wall s | cpu s (user+sys) | cpu us/row | peak mem MB |\n";
echo "|---|---|---:|---:|---:|---:|---:|\n";
foreach ($results as $name => $perDriver) {
    foreach ($perDriver as $driver => $r) {
        echo "| {$name} | {$driver} | {$r['rows']} | {$fmt($r['wall_s'])} | {$fmt($r['cpu_s'])} | {$fmt($r['cpu_us_per_row'], 2)} | {$fmt($r['peak_mem_mb'], 1)} |\n";
    }
}

$json = [
    'date' => date('c'),
    'php' => PHP_VERSION,
    'fiberio' => phpversion('fiberio'),
    'server' => $version,
    'transport' => ['fledge' => "tcp://{$opt['host']}:{$opt['port']}", 'pdo_mysql' => $stockStatus, 'pdo_mysql+fiberio' => $fiberStatus],
    'runs' => $runs,
    'warmups' => WARMUPS,
    'scale' => $scale,
    'results' => $results,
];
$file = __DIR__ . '/results/local-mariadb11-' . date('Y-m-d') . '.json';
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nJSON written to {$file}\n";
