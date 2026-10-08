<?php

declare(strict_types=1);

/**
 * Local six-way MySQL driver benchmark (SELECT only). Four columns run at raw
 * PDO level; "native-driver" and "laravel stock" run through a Laravel Connection.
 *
 * Usage: php -d extension=<path>/fiberio.so mysql_driver_bench_local.php
 *        [--host=127.0.0.1] [--port=13307] [--user=fledge] [--password=fledge]
 *        [--database=fledge_test] [--runs=5] [--scale=0.2]
 *        [--revolt-waiter=~/Development/Github/php-fiberio/src/RevoltWaiter.php]
 *
 * Local sibling of mysql_driver_bench.php: no Laravel app, fledge-fiber's own
 * vendor autoload, tables from seed_local_mariadb.php in the docker mariadb:11.
 * All columns use TCP to the same host:port and credentials:
 *   - "fledge"            : FledgeMySqlPdo on a MysqlConnectionPool (32 connections)
 *   - "pdo_mysql"         : one stock PDO; in the fiber cases its blocking I/O
 *                           serialises the fibers
 *   - "pdo_mysql+fiberio" : FiberIo\enable(new FiberIo\RevoltWaiter) and a pool
 *                           of 32 real PDO connections, one per fiber. Bulk and
 *                           point cases run inside one fiber so the waiter path
 *                           is the one measured. The point case prepares one
 *                           statement and reuses it for every lookup.
 *   - "pdo_mysql+fiberio (prepare per call)" : the same 32 PDOs and waiter, but
 *                           the point case prepares a new statement for every
 *                           lookup (prepare + execute, as Laravel does). Bulk
 *                           and concurrency cases already prepare per call in
 *                           every raw PDO column, so they share the fiberio
 *                           column's code path (measured again, not copied).
 *   - "laravel stock"     : a blocking stock Illuminate MariaDbConnection /
 *                           MySqlConnection built by ConnectionFactory (driver
 *                           mariadb/mysql, one pdo_mysql PDO opened with fiberio
 *                           disabled, no pool, no lease), running the same
 *                           Connection::select() workloads as native-driver, to
 *                           isolate pool/lease overhead. Its blocking I/O cannot
 *                           overlap, so the concurrency cases run its queries
 *                           sequentially (labelled "(sequential)").
 *   - "native-driver"     : the `fledge-mariadb-native` driver, i.e. a
 *                           NativeMariaDbConnection (NativePdoPool of 32 pdo_mysql
 *                           PDOs on fiberio, one leased per fiber) built by
 *                           NativeMariaDbConnector and queried through
 *                           Connection::select(). Unlike the other columns this
 *                           includes Laravel's Connection overhead (query
 *                           preparation, lease scope, stdClass hydration); the
 *                           point case prepares per call, as Laravel does.
 *
 * The hook is enabled only while a fiberio column runs (their PDOs are opened
 * while enabled), so fledge's own sockets never go through fiberio. The native
 * column uses the driver's own Native\RevoltWaiter, the others php-fiberio's.
 */

use FiberIo\RevoltWaiter;
use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnectionPool;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Fledge\Fiber\Database\Native\NativePdo;
use Fledge\Fiber\Database\Native\RevoltWaiter as NativeRevoltWaiter;
use Fledge\Fiber\Database\Pdo\FledgeMySqlPdo;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;

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
    'revolt-waiter' => (getenv('HOME') ?: '').'/Development/Github/php-fiberio/src/RevoltWaiter.php',
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
    fail('host must be a loopback address (TCP), got '.$opt['host']);
}
if (! extension_loaded('fiberio')) {
    fail('fiberio is not loaded; run with php -d extension=<path>/modules/fiberio.so');
}
if (! is_file($opt['revolt-waiter'])) {
    fail("RevoltWaiter not found at {$opt['revolt-waiter']}");
}

require __DIR__.'/../../vendor/autoload.php';
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

$waiter = new RevoltWaiter;
FiberIo\enable($waiter);
$fiberPool = [];
for ($i = 0; $i < FIBERS; $i++) {
    $fiberPool[] = $newPdo();
}
FiberIo\disable();

$version = (string) $stock->query('select version()')->fetchColumn();

// native-driver: the connector enables fiberio itself and returns a lazy pool;
// the hook is disabled again right after and re-enabled around this column's runs.
$isMaria = str_contains($version, 'MariaDB');
$nativeConfig = [
    'driver' => $isMaria ? 'fledge-mariadb-native' : 'fledge-mysql-native',
    'name' => 'native-driver',
    'host' => $opt['host'],
    'port' => $opt['port'],
    'database' => $opt['database'],
    'username' => $opt['user'],
    'password' => $opt['password'],
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'pool_size' => FIBERS,
    'pool_idle_timeout' => 60,
];
$nativePool = ($isMaria ? new NativeMariaDbConnector : new NativeMySqlConnector)->connect($nativeConfig);
$native = $isMaria
    ? new NativeMariaDbConnection($nativePool, $opt['database'], '', $nativeConfig)
    : new NativeMySqlConnection($nativePool, $opt['database'], '', $nativeConfig);
FiberIo\disable();
$nativeWaiter = new NativeRevoltWaiter;

// Open the first pooled PDO while the hook is on, then release the main-context lease with a query.
FiberIo\enable($nativeWaiter);
$nativePdo = $native->getPdo();
$nativeStatus = (string) $nativePdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
$nativeVersion = (string) $native->selectOne('select version() as v')->v;
FiberIo\disable();

// laravel stock: plain Illuminate connection, opened with the hook disabled so its
// PDO uses the blocking stock transport.
$laravelStockConfig = array_merge($nativeConfig, ['driver' => $isMaria ? 'mariadb' : 'mysql', 'name' => 'laravel-stock']);
unset($laravelStockConfig['pool_size'], $laravelStockConfig['pool_idle_timeout']);
$laravelStock = (new ConnectionFactory(new Container))->make($laravelStockConfig, 'laravel-stock');
$laravelStockPdo = $laravelStock->getPdo();
$laravelStockStatus = (string) $laravelStockPdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
$laravelStockVersion = (string) $laravelStock->selectOne('select version() as v')->v;

$stockStatus = (string) $stock->getAttribute(PDO::ATTR_CONNECTION_STATUS);
$fiberStatus = (string) $fiberPool[0]->getAttribute(PDO::ATTR_CONNECTION_STATUS);
$st = $fledge->prepare('select version() as v');
$st->execute();
$fledgeVersion = (string) $st->fetchAll(PDO::FETCH_ASSOC)[0]['v'];
if (! str_contains($stockStatus, 'TCP/IP') || ! str_contains($fiberStatus, 'TCP/IP') || ! str_contains($nativeStatus, 'TCP/IP') || ! str_contains($laravelStockStatus, 'TCP/IP')) {
    fail("pdo_mysql is not on TCP: {$stockStatus} / {$fiberStatus} / {$nativeStatus} / {$laravelStockStatus}");
}
if ($fledgeVersion !== $version || $nativeVersion !== $version || $laravelStockVersion !== $version) {
    fail("drivers reached different servers: fledge {$fledgeVersion}, native {$nativeVersion}, laravel stock {$laravelStockVersion}, pdo_mysql {$version}");
}
$laravelStockClass = $isMaria ? MariaDbConnection::class : MySqlConnection::class;
if ($laravelStock::class !== $laravelStockClass || ! $laravelStockPdo instanceof PDO || $laravelStockPdo instanceof NativePdo) {
    fail('laravel stock is not a plain '.$laravelStockClass.' on a stock PDO: '.$laravelStock::class.' / '.$laravelStockPdo::class);
}
if (! $nativePdo instanceof NativePdo || $native->getRawPdo() !== $nativePool) {
    fail('native-driver did not resolve to a NativePdoPool leasing NativePdo: '.$nativePdo::class);
}

$transport = "Transport check\n"
    ."  fledge-mariadb    : tcp://{$opt['host']}:{$opt['port']} (FledgeMySqlPdo, pool ".FIBERS.")\n"
    ."  pdo_mysql         : {$stockStatus}, port {$opt['port']}\n"
    ."  pdo_mysql+fiberio : {$fiberStatus}, port {$opt['port']}, ".FIBERS." PDO connections, RevoltWaiter (also the prepare-per-call column)\n"
    ."  laravel stock     : {$laravelStockClass} ({$laravelStockConfig['driver']} driver), {$laravelStockStatus}, port {$opt['port']}, one blocking PDO, no fiberio, no pool\n"
    ."  native-driver     : {$nativeConfig['driver']}, {$nativeStatus}, port {$opt['port']}, pool ".FIBERS.", Native\\RevoltWaiter, via Laravel Connection\n"
    ."  server            : {$version}\n"
    .'  php               : '.PHP_VERSION.', fiberio '.phpversion('fiberio').", runs={$runs}, scale={$scale}\n";
echo $transport."\n";

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
        $prefix = dechex(mt_rand(0, 7)).sprintf('%06x', mt_rand(0, 0xFFFFFF));
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
// rows(f, sql, params): fetch all rows on fiber f's connection;
// point(f, sql): a Closure(array $params): int that runs the lookup repeatedly (PDO drivers reuse one
// prepared statement unless $prepareEach, which prepares a new statement for every lookup).
$pdoDriver = fn (Closure $conn, Closure $single, ?object $waiter, bool $prepareEach = false): array => [
    'single' => $single,
    'waiter' => $waiter,
    'rows' => fn (int $f, string $sql, array $params = []) => rows($conn($f), $sql, $params),
    'point' => function (int $f, string $sql) use ($conn, $prepareEach): Closure {
        if ($prepareEach) {
            return function (array $params) use ($conn, $f, $sql): int {
                $st = $conn($f)->prepare($sql);
                $st->execute($params);

                return count($st->fetchAll(PDO::FETCH_ASSOC));
            };
        }
        $st = $conn($f)->prepare($sql);

        return function (array $params) use ($st): int {
            $st->execute($params);

            return count($st->fetchAll(PDO::FETCH_ASSOC));
        };
    },
];
$drivers = [
    'fledge' => $pdoDriver(fn (int $f) => $fledge, $direct, null),
    'pdo_mysql' => $pdoDriver(fn (int $f) => $stock, $direct, null),
    'pdo_mysql+fiberio' => $pdoDriver(fn (int $f) => $fiberPool[$f], $inFiber, $waiter),
    // Same PDOs; only the point case differs (new prepared statement per lookup).
    'pdo_mysql+fiberio (prepare per call)' => $pdoDriver(fn (int $f) => $fiberPool[$f], $inFiber, $waiter, true),
    // Blocking stock Illuminate connection, same Connection::select() path as native-driver.
    // Blocking I/O cannot overlap, so the concurrency cases run its queries sequentially.
    'laravel stock' => [
        'single' => $direct,
        'waiter' => null,
        'sequential' => true,
        'rows' => fn (int $f, string $sql, array $params = []) => $laravelStock->select($sql, $params),
        'point' => fn (int $f, string $sql): Closure => fn (array $params): int => count($laravelStock->select($sql, $params)),
    ],
    // Through the Laravel Connection: each call leases a PDO for the calling fiber.
    'native-driver' => [
        'single' => $inFiber,
        'waiter' => $nativeWaiter,
        'rows' => fn (int $f, string $sql, array $params = []) => $native->select($sql, $params),
        'point' => fn (int $f, string $sql): Closure => fn (array $params): int => count($native->select($sql, $params)),
    ],
];

// ---------------------------------------------------------------- cases
/** @var array<string, Closure(array): Closure> $cases case => factory(driver) */
$cases = [];

foreach ($plan as $label => $p) {
    $sql = "select * from `{$p['table']}` where `{$p['pk']}` >= ? order by `{$p['pk']}` limit {$p['rows']}";
    $cases["bulk {$label} ({$p['rows']}) raw PDO"] = fn (array $d) => $d['single'](
        fn () => count($d['rows'](0, $sql, [$p['start']])),
    );
}

$pointSql = "select * from `{$ft}` where `{$fpk}` = ? limit 1";
$cases["point feed_items ({$pointN}) raw PDO"] = fn (array $d) => $d['single'](function () use ($d, $pointSql, $pointIds) {
    $n = 0;
    $lookup = $d['point'](0, $pointSql);
    foreach ($pointIds as $id) {
        $n += $lookup([$id]);
    }

    return $n;
});

$rangeSql = "select * from `{$ft}` where `{$fpk}` >= ? order by `{$fpk}` limit ".RANGE_ROWS;
// Run $work(f) for every fiber: concurrently via async(), or one after the other for sequential drivers.
$runFibers = function (array $d, int $fibers, Closure $work): int {
    if ($d['sequential'] ?? false) {
        $n = 0;
        for ($f = 0; $f < $fibers; $f++) {
            $n += $work($f);
        }

        return $n;
    }
    $futures = [];
    for ($f = 0; $f < $fibers; $f++) {
        $futures[] = async(fn () => $work($f));
    }

    return array_sum(await($futures));
};

$cases["concurrency {$fibers} fibers x {$perFiber} range queries (".RANGE_ROWS.' rows)'] = fn (array $d) => fn () => $runFibers($d, $fibers, function (int $f) use ($d, $fiberStarts, $rangeSql) {
    $n = 0;
    foreach ($fiberStarts[$f] as $start) {
        $n += count($d['rows']($f, $rangeSql, [$start]));
    }

    return $n;
});

$cases["concurrency {$fibers} fibers x SELECT SLEEP(".SLEEP_SECONDS.')'] = fn (array $d) => fn () => $runFibers(
    $d,
    $fibers,
    fn (int $f) => count($d['rows']($f, 'select sleep('.SLEEP_SECONDS.') as s')),
);

// ---------------------------------------------------------------- run
$results = [];
foreach ($cases as $name => $factory) {
    fwrite(STDERR, "running: {$name}\n");
    foreach ($drivers as $driver => $d) {
        if ($d['waiter'] !== null) {
            FiberIo\enable($d['waiter']);
        }
        try {
            $results[$name][$driver] = bench($factory($d), $runs);
        } finally {
            if ($d['waiter'] !== null) {
                FiberIo\disable();
            }
        }
    }
}

// ---------------------------------------------------------------- output
$fmt = fn (?float $v, int $d = 3) => $v === null ? '-' : number_format($v, $d, '.', '');

$table = "| case | driver | rows | wall s | cpu s (user+sys) | cpu us/row | peak mem MB |\n";
$table .= "|---|---|---:|---:|---:|---:|---:|\n";
foreach ($results as $name => $perDriver) {
    foreach ($perDriver as $driver => $r) {
        // The blocking laravel stock column runs the concurrency cases one query after the other.
        $label = $driver === 'laravel stock' && str_starts_with($name, 'concurrency') ? 'laravel stock (sequential)' : $driver;
        $table .= "| {$name} | {$label} | {$r['rows']} | {$fmt($r['wall_s'])} | {$fmt($r['cpu_s'])} | {$fmt($r['cpu_us_per_row'], 2)} | {$fmt($r['peak_mem_mb'], 1)} |\n";
    }
}

// native-driver relative to the other columns (>1 means native is slower / costlier).
$ratioAgainst = ['laravel stock', 'pdo_mysql+fiberio (prepare per call)', 'pdo_mysql+fiberio'];
$ratioOf = fn (float $a, float $b): ?float => $b > 0 ? $a / $b : null;
$ratios = [];
$ratioTable = "| case | native / against | wall x | cpu x |\n|---|---|---:|---:|\n";
foreach ($results as $name => $perDriver) {
    foreach ($ratioAgainst as $other) {
        $w = $ratioOf($perDriver['native-driver']['wall_s'], $perDriver[$other]['wall_s']);
        $c = $ratioOf($perDriver['native-driver']['cpu_s'], $perDriver[$other]['cpu_s']);
        $ratios[$name][$other] = ['wall' => $w, 'cpu' => $c];
        $ratioTable .= "| {$name} | native / {$other} | {$fmt($w, 2)} | {$fmt($c, 2)} |\n";
    }
}

$notes = 'Notes: native-driver and laravel stock go through Connection::select(); the other columns are raw PDO. '
    .'pdo_mysql+fiberio (prepare per call) differs from pdo_mysql+fiberio only in the point case (new prepared statement per lookup); '
    .'its bulk and concurrency cases use the same code path as pdo_mysql+fiberio (every raw PDO column prepares per call there) and are measured again. '
    ."laravel stock is blocking, so its concurrency cases run sequentially.\n";

echo $table."\n".$ratioTable."\n".$notes;

$json = [
    'date' => date('c'),
    'php' => PHP_VERSION,
    'fiberio' => phpversion('fiberio'),
    'server' => $version,
    'transport' => [
        'fledge' => "tcp://{$opt['host']}:{$opt['port']}",
        'pdo_mysql' => $stockStatus,
        'pdo_mysql+fiberio' => $fiberStatus,
        'pdo_mysql+fiberio (prepare per call)' => $fiberStatus,
        'laravel stock' => "{$laravelStockConfig['driver']}: {$laravelStockStatus}",
        'native-driver' => "{$nativeConfig['driver']}: {$nativeStatus}",
    ],
    'runs' => $runs,
    'warmups' => WARMUPS,
    'scale' => $scale,
    'notes' => $notes,
    'results' => $results,
    'native_ratios' => $ratios,
];
$file = __DIR__.'/results/local-mariadb11-'.date('Y-m-d').'.json';
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// Command line without the machine-specific extension path.
$command = 'php -d extension=<path>/fiberio.so bench/database/mysql_driver_bench_local.php '.implode(' ', array_slice($argv, 1));
$mdFile = __DIR__.'/results/local-mariadb11-'.date('Y-m-d').'.md';
file_put_contents($mdFile, "# Local benchmark: fledge-mariadb vs pdo_mysql vs pdo_mysql + fiberio (reused and prepare per call) vs laravel stock vs native-driver\n\n"
    ."Docker {$version} (127.0.0.1:{$opt['port']}, TCP for all columns), run with `".trim($command).'` on macOS (PHP '.PHP_VERSION.'). '
    ."native-driver ({$nativeConfig['driver']}, NativePdoPool of ".FIBERS.' pdo_mysql PDOs on fiberio) and laravel stock (blocking stock Illuminate connection) go through a Laravel Connection; the other columns are raw PDO. '
    ."The concurrency cases run laravel stock sequentially.\n\n"
    ."```\n{$transport}```\n\n{$table}\nnative-driver relative to the other columns:\n\n{$ratioTable}\n{$notes}");
echo "\nJSON written to {$file}\nMarkdown written to {$mdFile}\n";
