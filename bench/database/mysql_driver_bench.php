<?php

declare(strict_types=1);

/**
 * fledge-mariadb vs pdo_mysql driver benchmark (SELECT only, read-only).
 *
 * Usage: php mysql_driver_bench.php <app-path> [--runs=5] [--scale=1.0]
 *
 * Boots the Laravel/Fledge app at <app-path> and compares two connections on
 * the SAME unix socket and credentials:
 *   - "fledge"    : the app's `mariadb` connection (driver fledge-mariadb)
 *   - "pdo_mysql" : runtime clone `mariadb_pdo` with driver `mariadb`
 *
 * Concurrency uses Fledge\Async\async() + Fledge\Async\Future\await() (the same
 * primitives as FiberDB::concurrent()). pdo_mysql runs in the very same fibers,
 * where its blocking I/O serialises them.
 *
 * Safety: SELECT only; every query has a LIMIT and/or a PK range.
 * Run with: nice -n 10 php mysql_driver_bench.php /home/sites/<site>/current
 */

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

const TABLES = [
    // label => [table, pk, rows]
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
$appPath = null;
$runs = 5;
$scale = 1.0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--runs=')) {
        $runs = max(1, (int) substr($arg, 7));
    } elseif (str_starts_with($arg, '--scale=')) {
        $scale = max(0.0001, (float) substr($arg, 8));
    } elseif ($appPath === null) {
        $appPath = rtrim($arg, '/');
    }
}
if ($appPath === null || ! is_file("{$appPath}/vendor/autoload.php") || ! is_file("{$appPath}/bootstrap/app.php")) {
    fail('usage: php mysql_driver_bench.php <app-path> [--runs=5] [--scale=1.0]');
}

chdir($appPath);
require "{$appPath}/vendor/autoload.php";
$app = require "{$appPath}/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------- connections
$base = config('database.connections.mariadb');
if (! is_array($base)) {
    fail('app has no `mariadb` database connection');
}
if (($base['driver'] ?? null) !== 'fledge-mariadb') {
    fail('connection `mariadb` must use driver fledge-mariadb, got ' . var_export($base['driver'] ?? null, true));
}

// Findings from FledgeMySqlConnector::buildConfig(): `unix_socket` is honoured
// (used as MysqlConfig host; a host starting with "/" becomes unix://<path>).
// The pool must hold one connection per fiber for the concurrency cases.
$base['pool_size'] = max((int) ($base['pool_size'] ?? 0), FIBERS);
config(['database.connections.mariadb' => $base]);

$socket = (string) ($base['unix_socket'] ?? '');
if ($socket === '' || ! str_starts_with($socket, '/')) {
    fwrite(STDOUT, "fledge-mariadb connection has no unix_socket configured (host=" . ($base['host'] ?? '?') . "); refusing to benchmark TCP vs socket.\n");
    exit(2);
}
if (@filetype($socket) !== 'socket') {
    fail("unix_socket {$socket} is not a socket file");
}

$clone = $base;
$clone['driver'] = 'mariadb';
unset($clone['pool_size'], $clone['pool_idle_timeout']);
config(['database.connections.mariadb_pdo' => $clone]);

$conns = ['fledge' => 'mariadb', 'pdo_mysql' => 'mariadb_pdo'];

$fledgePdo = DB::connection('mariadb')->getPdo();
$nativePdo = DB::connection('mariadb_pdo')->getPdo();

if (! str_contains($fledgePdo::class, 'Fledge')) {
    fail('mariadb connection did not resolve to a Fledge PDO: ' . $fledgePdo::class);
}
if (str_contains($nativePdo::class, 'Fledge')) {
    fail('mariadb_pdo resolved to a Fledge PDO: ' . $nativePdo::class);
}
$nativeStatus = (string) $nativePdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
if (! str_contains($nativeStatus, 'UNIX socket')) {
    fail("pdo_mysql is not on a unix socket: {$nativeStatus}");
}
$nativeSocket = (string) DB::connection('mariadb_pdo')->selectOne('select @@socket as s')->s;
$fledgeSocket = (string) DB::connection('mariadb')->selectOne('select @@socket as s')->s;
if ($nativeSocket !== $fledgeSocket) {
    fail("server socket differs: fledge={$fledgeSocket} pdo_mysql={$nativeSocket}");
}

echo "Transport check\n";
echo "  configured unix_socket : {$socket}\n";
echo "  fledge-mariadb         : unix://{$socket} (class " . $fledgePdo::class . ", server @@socket={$fledgeSocket})\n";
echo "  pdo_mysql              : {$nativeStatus} (server @@socket={$nativeSocket}), same path {$socket}\n";
echo '  server                 : ' . DB::connection('mariadb')->selectOne('select version() as v')->v . "\n";
echo '  php                    : ' . PHP_VERSION . ", runs={$runs}, scale={$scale}\n\n";

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

/** Cheap MIN/MAX read, then a deterministic random start inside [min, max]. */
function pkBounds(string $conn, string $table, string $pk): array
{
    $r = DB::connection($conn)->selectOne("select min(`{$pk}`) as lo, max(`{$pk}`) as hi from `{$table}`");
    if ($r->lo === null) {
        fail("table {$table} is empty");
    }
    if (! is_numeric($r->lo)) {
        // String PK (e.g. char(36) uuid): keyset pagination, no numeric bounds.
        return [(string) $r->lo, (string) $r->hi];
    }

    return [(int) $r->lo, (int) $r->hi];
}

/**
 * Keyset start for a string PK: a seeded random hex prefix (first nibble 0-7 so
 * a full page of rows normally follows), resolved to a real key with one
 * bounded `pk >= ? order by pk limit 1` query. Falls back to the minimum key.
 */
function keysetStart(string $conn, string $table, string $pk, string $lo): string
{
    $prefix = dechex(mt_rand(0, 7)) . bin2hex(random_bytes_seeded(3));
    $r = DB::connection($conn)->selectOne("select `{$pk}` as k from `{$table}` where `{$pk}` >= ? order by `{$pk}` limit 1", [$prefix]);

    return $r === null ? $lo : (string) $r->k;
}

/** Deterministic bytes from mt_rand so the seeded run is reproducible. */
function random_bytes_seeded(int $n): string
{
    $b = '';
    for ($i = 0; $i < $n; $i++) {
        $b .= chr(mt_rand(0, 255));
    }

    return $b;
}

function randomStart(int $lo, int $hi, int $reserve = 0): int
{
    return mt_rand($lo, max($lo, $hi - $reserve));
}

mt_srand(20261007);

// ---------------------------------------------------------------- plan
// Same start keys for both drivers so they read identical rows. Schema (host-2):
// tk_raw_json.id char(36) PK (keyset); koop_document_metadata.id and feed_items.id bigint unsigned AUTO_INCREMENT PK.
$plan = [];
foreach (TABLES as $label => [$table, $pk, $rowsWanted]) {
    [$lo, $hi] = pkBounds('mariadb', $table, $pk);
    $rowsN = scaled($rowsWanted, $scale);
    $start = is_string($lo) ? keysetStart('mariadb', $table, $pk, $lo) : randomStart($lo, $hi, $rowsN * 2);
    $plan[$label] = ['table' => $table, 'pk' => $pk, 'rows' => $rowsN, 'start' => $start, 'lo' => $lo, 'hi' => $hi];
}

// Point ids: real ids sampled from a PK range of feed_items (bounded by LIMIT).
[$ft, $fpk] = [TABLES['feed_items'][0], TABLES['feed_items'][1]];
$pointN = scaled(POINT_QUERIES, $scale);
$sampleStart = randomStart($plan['feed_items']['lo'], $plan['feed_items']['hi'], $pointN * 2);
$pointIds = array_map(
    fn ($r) => (int) $r->id,
    DB::connection('mariadb')->select("select `{$fpk}` as id from `{$ft}` where `{$fpk}` >= ? order by `{$fpk}` limit {$pointN}", [$sampleStart]),
);
if ($pointIds === []) {
    $pointIds = array_map(
        fn ($r) => (int) $r->id,
        DB::connection('mariadb')->select("select `{$fpk}` as id from `{$ft}` order by `{$fpk}` limit {$pointN}"),
    );
}
shuffle($pointIds);

// Range starts for the fiber range-query case.
$fibers = FIBERS;
$perFiber = scaled(QUERIES_PER_FIBER, max($scale, 0.1));
$fiberStarts = [];
for ($f = 0; $f < $fibers; $f++) {
    for ($q = 0; $q < $perFiber; $q++) {
        $fiberStarts[$f][] = randomStart($plan['feed_items']['lo'], $plan['feed_items']['hi'], RANGE_ROWS * 2);
    }
}

// ---------------------------------------------------------------- cases
/** @return array<string, array<string, Closure(string): Closure>> case => driver-agnostic factory(connName) */
$cases = [];

foreach ($plan as $label => $p) {
    $sql = "select * from `{$p['table']}` where `{$p['pk']}` >= ? order by `{$p['pk']}` limit {$p['rows']}";
    $cases["bulk {$label} ({$p['rows']}) laravel select()"] = fn (string $c) => fn () => count(DB::connection($c)->select($sql, [$p['start']]));
    $cases["bulk {$label} ({$p['rows']}) raw PDO"] = function (string $c) use ($sql, $p) {
        $pdo = DB::connection($c)->getPdo();

        return function () use ($pdo, $sql, $p) {
            $st = $pdo->prepare($sql);
            $st->execute([$p['start']]);

            return count($st->fetchAll(PDO::FETCH_ASSOC));
        };
    };
}

$pointSql = "select * from `{$ft}` where `{$fpk}` = ? limit 1";
$cases["point feed_items ({$pointN}) laravel select()"] = fn (string $c) => function () use ($c, $pointSql, $pointIds) {
    $n = 0;
    foreach ($pointIds as $id) {
        $n += count(DB::connection($c)->select($pointSql, [$id]));
    }

    return $n;
};
$cases["point feed_items ({$pointN}) raw PDO"] = function (string $c) use ($pointSql, $pointIds) {
    $pdo = DB::connection($c)->getPdo();

    return function () use ($pdo, $pointSql, $pointIds) {
        $n = 0;
        $st = $pdo->prepare($pointSql);
        foreach ($pointIds as $id) {
            $st->execute([$id]);
            $n += count($st->fetchAll(PDO::FETCH_ASSOC));
        }

        return $n;
    };
};

$rangeSql = "select * from `{$ft}` where `{$fpk}` >= ? order by `{$fpk}` limit " . RANGE_ROWS;
$cases["concurrency {$fibers} fibers x {$perFiber} range queries (" . RANGE_ROWS . ' rows)'] = fn (string $c) => function () use ($c, $fibers, $fiberStarts, $rangeSql) {
    $futures = [];
    for ($f = 0; $f < $fibers; $f++) {
        $futures[] = async(function () use ($c, $f, $fiberStarts, $rangeSql) {
            $n = 0;
            foreach ($fiberStarts[$f] as $start) {
                $n += count(DB::connection($c)->select($rangeSql, [$start]));
            }

            return $n;
        });
    }

    return array_sum(await($futures));
};

$cases["concurrency {$fibers} fibers x SELECT SLEEP(" . SLEEP_SECONDS . ')'] = fn (string $c) => function () use ($c, $fibers) {
    $futures = [];
    for ($f = 0; $f < $fibers; $f++) {
        $futures[] = async(fn () => count(DB::connection($c)->select('select sleep(' . SLEEP_SECONDS . ') as s')));
    }

    return array_sum(await($futures));
};

// ---------------------------------------------------------------- run
$results = [];
foreach ($cases as $name => $factory) {
    fwrite(STDERR, "running: {$name}\n");
    foreach ($conns as $driver => $conn) {
        $results[$name][$driver] = bench($factory($conn), $runs);
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
    'app' => $appPath,
    'server' => DB::connection('mariadb')->selectOne('select version() as v')->v,
    'transport' => ['unix_socket' => $socket, 'fledge' => "unix://{$socket}", 'pdo_mysql' => $nativeStatus],
    'runs' => $runs,
    'warmups' => WARMUPS,
    'scale' => $scale,
    'results' => $results,
];
$file = __DIR__ . '/mysql_driver_bench-' . date('Y-m-d') . '.json';
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nJSON written to {$file}\n";
