<?php

declare(strict_types=1);

/**
 * Event loop driver benchmark: idle TCP connections plus ping-pong traffic.
 *
 * Answers one question: what does each watched-but-idle connection cost a driver, in throughput, latency and CPU?
 * The poll driver (Io\Poll: kqueue, epoll or poll(2)) is compared with Revolt's StreamSelectDriver.
 *
 * Usage:
 *   php bench/eventloop/idle_connections.php [--driver=select,poll] [--backend=auto|poll|epoll|kqueue]
 *       [--idle=10,400,1000,10000] [--pairs=8] [--seconds=10] [--idle-seconds=10] [--warmup=1] [--runs=3]
 *       [--max-spread=0.10] [--json]
 *
 * Every run is a separate child process with one driver, installed through Revolt's EventLoop API. A child:
 *   1. raises the open file soft limit (posix_setrlimit) to what the configuration needs;
 *   2. opens the ping-pong pairs first (low descriptor numbers), then N idle loopback TCP connections; both ends
 *      of every connection live in this process and both ends carry a readable callback that never fires, so N
 *      idle connections are 2N watched descriptors;
 *   3. ping-pong phase: every pair bounces one byte back and forth, each hop driven by onReadable. After the
 *      warm-up it counts round trips, samples every round trip's latency with hrtime() and takes getrusage()
 *      user + system time over the window;
 *   4. idle phase: the pairs are cancelled, a 100 ms repeating timer runs for --idle-seconds next to the N idle
 *      connections. It reports the CPU time of the whole phase, and the CPU time from the first timer tick to the
 *      end divided by the ticks in between (the steady per-tick cost, without the one-off phase change).
 *
 * The parent runs --runs children per configuration, reports the median and min to max, and reruns a
 * configuration once when its round trip spread exceeds --max-spread. StreamSelectDriver cannot watch descriptors
 * numbered at or above FD_SETSIZE (1024); such a configuration is reported as "n/a (FD_SETSIZE)".
 *
 * Extra drivers for experiments: a script that sets `$benchDrivers = ['name' => fn (): Revolt\EventLoop\Driver => ...]`
 * and then requires this file can pass --driver=name. Children are started from the entry script, so they see the
 * same drivers.
 */

use Fledge\Async\EventLoop\Driver\PollDriver;
use Fledge\Async\EventLoop\LoopDriver;
use Io\Poll\Backend;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array<string, Closure(): Driver> $benchDrivers */
$benchDrivers ??= [];

exit(benchMain($argv, $benchDrivers));

/**
 * @param list<string> $argv
 * @param array<string, Closure(): Driver> $extraDrivers
 */
function benchMain(array $argv, array $extraDrivers): int
{
    $options = benchOptions($argv);

    if (isset($options['child'])) {
        return benchChild($options, $extraDrivers);
    }

    return benchParent($options);
}

/**
 * @param list<string> $argv
 * @return array<string, string>
 */
function benchOptions(array $argv): array
{
    $options = [
        'driver' => 'select,poll',
        'backend' => 'auto',
        'idle' => '10,400,1000,10000',
        'pairs' => '8',
        'seconds' => '10',
        'idle-seconds' => '10',
        'warmup' => '1',
        'runs' => '3',
        'max-spread' => '0.10',
    ];

    foreach (\array_slice($argv, 1) as $argument) {
        if (!\str_starts_with($argument, '--')) {
            \fwrite(\STDERR, "Unexpected argument: {$argument}\n");
            exit(2);
        }

        [$name, $value] = \array_pad(\explode('=', \substr($argument, 2), 2), 2, '1');
        $options[$name] = $value;
    }

    return $options;
}

/**
 * @param array<string, string> $options
 */
function benchParent(array $options): int
{
    $drivers = \array_values(\array_filter(\array_map('trim', \explode(',', $options['driver']))));
    $idleCounts = \array_map('intval', \explode(',', $options['idle']));
    $runs = \max(1, (int) $options['runs']);
    $maxSpread = (float) $options['max-spread'];
    $json = isset($options['json']);

    $load = \sys_getloadavg() ?: [0.0, 0.0, 0.0];
    $header = \sprintf(
        'PHP %s %s on %s %s; load %.2f %.2f %.2f; pairs %d; window %ss after %ss warm-up; idle phase %ss; %d runs',
        \PHP_VERSION,
        \PHP_ZTS ? 'ZTS' : 'NTS',
        \PHP_OS_FAMILY,
        \php_uname('m'),
        $load[0],
        $load[1],
        $load[2],
        (int) $options['pairs'],
        $options['seconds'],
        $options['warmup'],
        $options['idle-seconds'],
        $runs,
    );
    \fwrite(\STDERR, $header . "\n");

    if ($load[0] > 2.0) {
        \fwrite(\STDERR, \sprintf("WARNING: 1-minute load is %.2f (above 2); other jobs may skew the numbers.\n", $load[0]));
    }

    $results = [];

    foreach ($drivers as $driver) {
        foreach ($idleCounts as $idle) {
            $result = benchConfiguration($options, $driver, $idle, $runs);

            if ($result['status'] === 'ok' && $result['spread'] > $maxSpread) {
                \fwrite(\STDERR, \sprintf(
                    "  spread %.1f%% above %.0f%%, rerunning %s idle=%d\n",
                    $result['spread'] * 100,
                    $maxSpread * 100,
                    $driver,
                    $idle,
                ));
                $rerun = benchConfiguration($options, $driver, $idle, $runs);

                if ($rerun['status'] === 'ok' && $rerun['spread'] < $result['spread']) {
                    $result = $rerun;
                }

                $result['reran'] = true;
            }

            $results[] = $result;
        }
    }

    if ($json) {
        echo \json_encode([
            'php' => \PHP_VERSION,
            'zts' => \PHP_ZTS,
            'os' => \PHP_OS_FAMILY,
            'machine' => \php_uname('m'),
            'load' => $load,
            'options' => $options,
            'results' => $results,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";

        return 0;
    }

    benchTable($results, $maxSpread);

    return 0;
}

/**
 * @param array<string, string> $options
 * @return array<string, mixed>
 */
function benchConfiguration(array $options, string $driver, int $idle, int $runs): array
{
    $samples = [];
    $label = $driver;

    for ($run = 1; $run <= $runs; $run++) {
        $child = benchSpawn($options, $driver, $idle);

        if (($child['error'] ?? null) === 'fd_setsize') {
            \fwrite(\STDERR, \sprintf("  %s idle=%d: n/a (FD_SETSIZE): %s\n", $driver, $idle, $child['message']));

            return ['driver' => $driver, 'label' => $child['label'] ?? $driver, 'idle' => $idle, 'status' => 'n/a (FD_SETSIZE)', 'message' => $child['message']];
        }

        if (isset($child['error'])) {
            \fwrite(\STDERR, \sprintf("  %s idle=%d: error: %s\n", $driver, $idle, $child['message'] ?? $child['error']));

            return ['driver' => $driver, 'label' => $child['label'] ?? $driver, 'idle' => $idle, 'status' => 'error', 'message' => $child['message'] ?? $child['error']];
        }

        $label = $child['label'];
        $samples[] = $child;
        \fwrite(\STDERR, \sprintf(
            "  %s idle=%d run %d: %.0f rt/s, p50 %.1f us, p99 %.1f us, CPU %.0f%%, idle %.3f CPU-s over %d ticks\n",
            $label,
            $idle,
            $run,
            $child['rt_per_s'],
            $child['p50_us'],
            $child['p99_us'],
            $child['cpu_pct'],
            $child['idle_cpu_s'],
            $child['idle_ticks'],
        ));
    }

    $summary = ['driver' => $driver, 'label' => $label, 'idle' => $idle, 'status' => 'ok', 'reran' => false];

    foreach (['rt_per_s', 'pair_min_rt_per_s', 'pair_max_rt_per_s', 'p50_us', 'p99_us', 'max_us', 'cpu_s', 'cpu_pct', 'idle_cpu_s', 'idle_cpu_per_tick_us'] as $metric) {
        $values = \array_column($samples, $metric);
        \sort($values);
        $summary[$metric] = ['median' => benchMedian($values), 'min' => $values[0], 'max' => $values[\count($values) - 1]];
    }

    $rt = $summary['rt_per_s'];
    $summary['spread'] = $rt['median'] > 0 ? ($rt['max'] - $rt['min']) / $rt['median'] : 0.0;
    $summary['rlimit'] = $samples[0]['rlimit'];
    $summary['runs'] = $samples;

    return $summary;
}

/**
 * @param array<string, string> $options
 * @return array<string, mixed>
 */
function benchSpawn(array $options, string $driver, int $idle): array
{
    $command = [\PHP_BINARY, '-d', 'memory_limit=-1', $_SERVER['SCRIPT_FILENAME'], '--child'];

    foreach ($options as $name => $value) {
        if (\in_array($name, ['driver', 'idle', 'json', 'runs', 'child'], true)) {
            continue;
        }

        $command[] = "--{$name}={$value}";
    }

    $command[] = "--driver={$driver}";
    $command[] = "--idle={$idle}";

    $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => \STDERR], $pipes);

    if (!\is_resource($process)) {
        return ['error' => 'spawn', 'message' => 'proc_open failed'];
    }

    $output = \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    $exit = \proc_close($process);

    $decoded = \json_decode(\trim((string) $output), true);

    if (!\is_array($decoded)) {
        return ['error' => 'child', 'message' => \sprintf('exit %d, output: %s', $exit, \substr((string) $output, 0, 300))];
    }

    return $decoded;
}

/**
 * @param list<array<string, mixed>> $results
 */
function benchTable(array $results, float $maxSpread): void
{
    $format = "%-16s %6s  %-24s %9s %8s %8s %9s %6s %9s %9s %7s\n";
    \printf($format, 'driver', 'idle', 'rt/s median (min-max)', 'rt/s/pair', 'p50 us', 'p99 us', 'max us', 'CPU%', 'idle CPU', 'us/tick', 'spread');

    foreach ($results as $result) {
        if ($result['status'] !== 'ok') {
            \printf("%-16s %6d  %s\n", $result['label'] ?? $result['driver'], $result['idle'], $result['status']);

            continue;
        }

        $rt = $result['rt_per_s'];
        \printf(
            $format,
            $result['label'],
            $result['idle'],
            \sprintf('%.0f (%.0f-%.0f)', $rt['median'], $rt['min'], $rt['max']),
            \sprintf('%.0f', $rt['median'] / \max(1, \count($result['runs'][0]['pair_rt_per_s']))),
            \sprintf('%.1f', $result['p50_us']['median']),
            \sprintf('%.1f', $result['p99_us']['median']),
            \sprintf('%.0f', $result['max_us']['median']),
            \sprintf('%.0f', $result['cpu_pct']['median']),
            \sprintf('%.3f s', $result['idle_cpu_s']['median']),
            \sprintf('%.1f', $result['idle_cpu_per_tick_us']['median']),
            \sprintf('%.1f%%%s', $result['spread'] * 100, $result['spread'] > $maxSpread ? '!' : ''),
        );
    }

    $limits = \array_unique(\array_filter(\array_column($results, 'rlimit')));
    \printf("open file soft limit used: %s\n", $limits ? \implode(', ', $limits) : 'n/a');
}

/**
 * @param list<int|float> $sorted
 */
function benchMedian(array $sorted): float
{
    $count = \count($sorted);
    $middle = \intdiv($count, 2);

    return $count % 2 === 1 ? (float) $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
}

/**
 * @param array<string, string> $options
 * @param array<string, Closure(): Driver> $extraDrivers
 */
function benchChild(array $options, array $extraDrivers): int
{
    $idle = (int) $options['idle'];
    $pairs = (int) $options['pairs'];
    $seconds = (float) $options['seconds'];
    $idleSeconds = (float) $options['idle-seconds'];
    $warmup = (float) $options['warmup'];

    $rlimit = benchRaiseFileLimit(2 * $idle + 2 * $pairs + 64);

    $driver = benchCreateDriver($options['driver'], $options['backend'], $extraDrivers);
    $label = $options['driver'];

    if ($driver->getHandle() instanceof Io\Poll\Context) {
        $label .= '(' . \strtolower($driver->getHandle()->getBackend()->name) . ')';
    }

    EventLoop::setDriver($driver);

    try {
        [$server, $address] = benchListen();

        // Pairs first, so their descriptors stay low; the idle connections come after them.
        $pairSockets = [];
        for ($i = 0; $i < $pairs; $i++) {
            $pairSockets[] = benchConnect($server, $address);
        }

        $idleSockets = [];
        for ($i = 0; $i < $idle; $i++) {
            $idleSockets[] = benchConnect($server, $address);
        }

        \fclose($server);
    } catch (\Throwable $exception) {
        echo \json_encode(['error' => 'setup', 'message' => $exception->getMessage(), 'rlimit' => $rlimit, 'label' => $label]), "\n";

        return 1;
    }

    $never = static function (string $id, $stream): void {
        throw new \LogicException('An idle connection became readable');
    };

    foreach ($idleSockets as [$client, $peer]) {
        EventLoop::onReadable($client, $never);
        EventLoop::onReadable($peer, $never);
    }

    // Ping-pong phase.
    $sampling = false;
    $latencies = [];
    $counts = \array_fill(0, $pairs, 0);
    $sentAt = \array_fill(0, $pairs, 0);
    $pairCallbacks = [];

    foreach ($pairSockets as $index => [$client, $peer]) {
        $pairCallbacks[] = EventLoop::onReadable($peer, static function (string $id, $stream): void {
            $data = \fread($stream, 64);

            if ($data === '' || $data === false) {
                if (\feof($stream)) {
                    throw new \RuntimeException('Ping-pong peer closed');
                }

                return;
            }

            \fwrite($stream, $data);
        });

        $pairCallbacks[] = EventLoop::onReadable(
            $client,
            static function (string $id, $stream) use ($index, &$sampling, &$latencies, &$counts, &$sentAt): void {
                $data = \fread($stream, 64);
                $now = \hrtime(true);

                if ($data === '' || $data === false) {
                    if (\feof($stream)) {
                        throw new \RuntimeException('Ping-pong client closed');
                    }

                    return;
                }

                if ($sampling) {
                    $latencies[] = $now - $sentAt[$index];
                    $counts[$index]++;
                }

                $sentAt[$index] = \hrtime(true);
                \fwrite($stream, 'x');
            },
        );
    }

    $window = [];

    EventLoop::delay($warmup, static function () use (&$sampling, &$window): void {
        $window['usage0'] = \getrusage();
        $window['wall0'] = \hrtime(true);
        $sampling = true;
    });

    EventLoop::delay($warmup + $seconds, static function () use (&$sampling, &$window): void {
        $sampling = false;
        $window['wall1'] = \hrtime(true);
        $window['usage1'] = \getrusage();
        EventLoop::getDriver()->stop();
    });

    foreach ($pairSockets as $index => [$client]) {
        $sentAt[$index] = \hrtime(true);
        \fwrite($client, 'x');
    }

    try {
        EventLoop::run();
    } catch (\Throwable $exception) {
        $message = \sprintf('%s(%d): %s', $exception::class, $exception->getCode(), $exception->getMessage());
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $message .= ' / ' . $previous->getMessage();
        }

        $error = \str_contains($message, 'FD_SETSIZE') ? 'fd_setsize' : 'run';
        echo \json_encode(['error' => $error, 'message' => \substr($message, 0, 240), 'rlimit' => $rlimit, 'label' => $label]), "\n";

        return 1;
    }

    $wall = ($window['wall1'] - $window['wall0']) / 1e9;
    $cpu = benchCpu($window['usage0'], $window['usage1']);
    $total = \array_sum($counts);
    \sort($latencies);
    $sampleCount = \count($latencies);
    $percentile = static fn (float $p): float => $sampleCount === 0
        ? 0.0
        : $latencies[\min($sampleCount - 1, (int) \floor($p * $sampleCount))] / 1000;
    $pairRates = \array_map(static fn (int $count): float => $count / $wall, $counts);

    // Idle phase: only the idle connections plus one repeating timer.
    foreach ($pairCallbacks as $callbackId) {
        EventLoop::cancel($callbackId);
    }

    $p50 = $percentile(0.50);
    $p99 = $percentile(0.99);
    $maxLatency = $sampleCount === 0 ? 0.0 : $latencies[$sampleCount - 1] / 1000;
    unset($latencies, $percentile);

    // The per-tick figure is taken from the first timer tick to the end, so one-off costs of the phase change
    // (cancelled callbacks, the restart of the loop) are not spread over the ticks.
    $ticks = 0;
    $firstTick = null;
    $timer = EventLoop::repeat(0.1, static function () use (&$ticks, &$firstTick): void {
        $firstTick ??= \getrusage();
        $ticks++;
    });
    EventLoop::delay($idleSeconds, static function () use ($timer): void {
        EventLoop::cancel($timer);
        EventLoop::getDriver()->stop();
    });

    $idleUsage0 = \getrusage();
    EventLoop::run();
    $idleUsage1 = \getrusage();
    $idleCpu = benchCpu($idleUsage0, $idleUsage1);
    $steadyCpu = $firstTick !== null ? benchCpu($firstTick, $idleUsage1) : 0.0;

    echo \json_encode([
        'label' => $label,
        'idle' => $idle,
        'pairs' => $pairs,
        'rlimit' => $rlimit,
        'load1' => (\sys_getloadavg() ?: [0.0])[0],
        'wall_s' => $wall,
        'round_trips' => $total,
        'rt_per_s' => $total / $wall,
        'pair_rt_per_s' => $pairRates,
        'pair_min_rt_per_s' => \min($pairRates),
        'pair_max_rt_per_s' => \max($pairRates),
        'p50_us' => $p50,
        'p99_us' => $p99,
        'max_us' => $maxLatency,
        'cpu_s' => $cpu,
        'cpu_pct' => 100 * $cpu / $wall,
        'idle_cpu_s' => $idleCpu,
        'idle_ticks' => $ticks,
        'idle_cpu_per_tick_us' => $ticks > 1 ? 1e6 * $steadyCpu / ($ticks - 1) : 0.0,
    ]), "\n";

    return 0;
}

/**
 * @param array<string, Closure(): Driver> $extraDrivers
 */
function benchCreateDriver(string $name, string $backend, array $extraDrivers): Driver
{
    if (isset($extraDrivers[$name])) {
        return ($extraDrivers[$name])();
    }

    if ($name === 'poll' && $backend !== 'auto') {
        foreach (Backend::cases() as $case) {
            if (\strtolower($case->name) === \strtolower($backend)) {
                return new PollDriver($case);
            }
        }

        throw new \InvalidArgumentException("Unknown backend: {$backend}");
    }

    return LoopDriver::create($name);
}

/**
 * Raises the open file soft limit to at least $needed and returns the soft limit in effect.
 */
function benchRaiseFileLimit(int $needed): string
{
    $limits = \posix_getrlimit(\POSIX_RLIMIT_NOFILE);

    if ($limits === false) {
        return 'unknown';
    }

    [$soft, $hard] = $limits;
    $softValue = $soft === 'unlimited' ? \PHP_INT_MAX : (int) $soft;

    if ($softValue < $needed) {
        $hardValue = $hard === 'unlimited' ? \POSIX_RLIMIT_INFINITY : (int) $hard;
        $target = $hard === 'unlimited' ? $needed : \min($needed, (int) $hard);
        \posix_setrlimit(\POSIX_RLIMIT_NOFILE, $target, $hardValue);
        $soft = \posix_getrlimit(\POSIX_RLIMIT_NOFILE)[0] ?? $soft;
    }

    return (string) $soft;
}

/**
 * @return array{resource, string}
 */
function benchListen(): array
{
    $context = \stream_context_create(['socket' => ['backlog' => 1024]]);
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);

    if ($server === false) {
        throw new \RuntimeException("listen failed: {$errstr}");
    }

    return [$server, (string) \stream_socket_get_name($server, false)];
}

/**
 * Opens one loopback connection and accepts its peer. Both ends are non-blocking, TCP_NODELAY, and SO_LINGER 0, so
 * process exit resets them instead of leaving thousands of TIME_WAIT entries between runs.
 *
 * @param resource $server
 * @return array{resource, resource}
 */
function benchConnect(mixed $server, string $address): array
{
    $client = \stream_socket_client("tcp://{$address}", $errno, $errstr, 5);

    if ($client === false) {
        throw new \RuntimeException("connect failed: {$errstr}");
    }

    $peer = \stream_socket_accept($server, 5);

    if ($peer === false) {
        throw new \RuntimeException('accept failed');
    }

    foreach ([$client, $peer] as $stream) {
        \stream_set_blocking($stream, false);
        $socket = \socket_import_stream($stream);

        if ($socket !== false && $socket !== null) {
            \socket_set_option($socket, \SOL_TCP, \TCP_NODELAY, 1);
            \socket_set_option($socket, \SOL_SOCKET, \SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        }
    }

    return [$client, $peer];
}

/**
 * @param array<string, int> $before
 * @param array<string, int> $after
 */
function benchCpu(array $before, array $after): float
{
    $seconds = static fn (array $usage): float => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;

    return $seconds($after) - $seconds($before);
}
