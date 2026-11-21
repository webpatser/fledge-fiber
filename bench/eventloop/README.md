# Event loop driver benchmark: idle connections

`idle_connections.php` measures what watched but idle connections cost an event loop driver. It compares
`Fledge\Async\EventLoop\Driver\PollDriver` (PHP 8.6 `Io\Poll`: kqueue on macOS, epoll on Linux, or poll(2)) with
Revolt's `StreamSelectDriver`, the driver `LoopDriver` resolves `auto` to today. The numbers feed the decision whether
`auto` should resolve to the poll driver.

## Running it

```bash
# macOS (or any host with PHP 8.6)
php bench/eventloop/idle_connections.php --driver=select,poll --idle=10,400,1000,10000

# poll driver on a specific Io\Poll backend
php bench/eventloop/idle_connections.php --driver=poll --backend=poll --idle=10,400

# Linux container (php:8.6-cli ZTS, see docker/php86-cli)
docker compose --profile linux run --rm php86 php bench/eventloop/idle_connections.php --driver=select,poll
```

| Option | Default | Meaning |
|---|---|---|
| `--driver` | `select,poll` | Comma-separated drivers: `select`, `poll`, `revolt` (anything `LoopDriver::create()` accepts) |
| `--backend` | `auto` | `Io\Poll` backend for `poll`: `auto`, `poll`, `epoll`, `kqueue` |
| `--idle` | `10,400,1000,10000` | Comma-separated numbers of idle connections (N) |
| `--pairs` | `8` | Ping-pong pairs |
| `--seconds` | `10` | Ping-pong measurement window |
| `--warmup` | `1` | Ping-pong warm-up before sampling starts |
| `--idle-seconds` | `10` | Length of the pure idle phase |
| `--runs` | `3` | Runs per configuration (each a separate process) |
| `--max-spread` | `0.10` | A configuration whose round trip spread exceeds this is rerun once |
| `--json` | off | Print JSON (all runs and summaries) instead of the table |

One configuration (driver, backend, N) takes about 22 seconds per run, so a full default matrix runs for several
minutes. Progress lines per run go to stderr, the table to stdout.

## What it does

Every run is its own PHP process with one driver installed through `Revolt\EventLoop::setDriver()`. Only the
`EventLoop` API is used (`onReadable`, `delay`, `repeat`), no stream classes, so the measurement is the driver and
Revolt's loop core.

1. The open file soft limit is raised with `posix_setrlimit()` to what the configuration needs; the table footer
   prints the limit in effect.
2. The 8 ping-pong pairs are opened first, so their descriptors stay low, then N idle loopback TCP connections. Both
   ends of every connection live in the same process, and both ends carry a readable callback that never fires: N
   idle connections are 2N watched descriptors. All sockets are non-blocking, `TCP_NODELAY` and `SO_LINGER 0`, so
   process exit resets them instead of leaving thousands of `TIME_WAIT` entries between runs.
3. Ping-pong phase: each pair bounces one byte back and forth, every hop driven by `onReadable`. After the warm-up
   the script counts round trips, records every round trip's latency with `hrtime()`, and takes `getrusage()` user
   plus system time over the window.
4. Idle phase: the pairs are cancelled, and a 100 ms repeating timer runs next to the N idle connections for
   `--idle-seconds`. About 96 timer ticks happen in 10 seconds, and every one of them is a blocking loop tick.

`StreamSelectDriver` cannot watch a descriptor numbered at or above `FD_SETSIZE` (1024). With 2N + 16 descriptors
that limit is crossed between N = 400 and N = 1000; such a run is reported as `n/a (FD_SETSIZE)`. N = 400 is the
largest comparable point.

## What the numbers mean

| Column | Meaning |
|---|---|
| rt/s | Round trips per second over all 8 pairs; median of the runs, with min to max |
| p50 / p99 us | Round trip latency percentiles in microseconds (median of the per-run percentiles) |
| CPU% | User plus system CPU over the ping-pong window as percent of wall time. The ping-pong saturates one core with every driver, so this column is always about 100% and only confirms the loop never waited |
| idle CPU-s | CPU seconds of the whole 10 s idle phase: what N idle connections cost while nothing happens |
| us/tick | CPU per idle-phase tick, measured from the first timer tick to the end (so the phase change itself is excluded) |
| spread | (max - min) / median of rt/s. A spread above 10% triggers one rerun of the configuration, and the run set with the lower spread is kept; `!` marks a spread that stayed above the limit |

The script prints `sys_getloadavg()` at start and warns when the 1-minute load is above 2.

## Results, 2026-11-21

PHP 8.6.0. Three runs per configuration, 10 s ping-pong window after 1 s warm-up, 10 s idle phase, 8 pairs.
Open file soft limit 1048576 on both platforms.

### macOS

Apple M5 (Mac17,3, 10 cores, 24 GB), macOS 27.2.1, PHP 8.6.0 NTS arm64. Other jobs were running: the 1-minute load
was between 2.5 and 9.3 during these runs, so treat the macOS figures as indicative.

| Driver | N | rt/s median (min-max) | p50 us | p99 us | idle CPU-s | us/tick |
|---|---:|---|---:|---:|---:|---:|
| select | 10 | 163186 (159634-165056) | 42.7 | 87.8 | 0.010 | 110 |
| poll (kqueue) | 10 | 168247 (112524-168786) | 47.1 | 54.5 | 0.010 | 103 |
| poll (poll) | 10 | 143695 (143134-146098) | 50.9 | 101.3 | 0.015 | 156 |
| select | 400 | 70192 (69868-70307) | 113.0 | 134.2 | 0.077 | 847 |
| poll (kqueue) | 400 | 155814 (155096-155929) | 50.1 | 61.4 | 0.022 | 227 |
| poll (poll) | 400 | 25490 (25381-27664) | 299.4 | 370.9 | 0.131 | 1360 |
| select | 1000 | n/a (FD_SETSIZE) | | | | |
| poll (kqueue) | 1000 | 137711 (132190-138431) | 56.6 | 69.6 | 0.038 | 395 |
| poll (poll) | 1000 | 10284 (9944-11196) | 775.8 | 943.8 | 0.156 | 1615 |
| select | 10000 | n/a (FD_SETSIZE) | | | | |
| poll (kqueue) | 10000 | 32691 (30108-33188) | 237.4 | 331.2 | 0.177 | 1835 |
| poll (poll) | 10000 | error: `FailedPollWaitException` code 3 (`ERROR_INVALID`) | | | | |

The kqueue N = 10 spread (33%) comes from one run at 112524 rt/s during a load spike; it stayed above 10% after the
rerun. The other two runs agree within 0.3%. The poll (poll) N = 1000 spread was 12%.

### Linux (container)

Docker Desktop LinuxKit VM (kernel 7.0.14, 5 CPUs, 10 GB) on the same Mac, image `php:8.6-cli` with pcntl and
sockets, PHP 8.6.0 ZTS aarch64. The load inside the VM stayed below 1.

| Driver | N | rt/s median (min-max) | p50 us | p99 us | idle CPU-s | us/tick |
|---|---:|---|---:|---:|---:|---:|
| select | 10 | 188749 (186159-189017) | 31.4 | 75.5 | 0.008 | 84 |
| poll (epoll) | 10 | 276304 (275998-276760) | 28.4 | 34.8 | 0.011 | 118 |
| poll (poll) | 10 | 263474 (262076-264080) | 29.9 | 36.3 | 0.014 | 150 |
| select | 400 | 54043 (52238-54874) | 121.3 | 293.2 | 0.058 | 592 |
| poll (epoll) | 400 | 230563 (230433-231523) | 34.2 | 41.1 | 0.023 | 243 |
| poll (poll) | 400 | 80684 (78598-81038) | 98.1 | 115.5 | 0.110 | 1144 |
| select | 1000 | n/a (FD_SETSIZE) | | | | |
| poll (epoll) | 1000 | 177967 (170619-178386) | 44.4 | 52.8 | 0.037 | 387 |
| poll (poll) | 1000 | 33476 (32578-33778) | 237.7 | 285.8 | 0.257 | 2758 |
| select | 10000 | n/a (FD_SETSIZE) | | | | |
| poll (epoll) | 10000 | 37089 (36578-37267) | 215.2 | 252.9 | 0.316 | 3351 |
| poll (poll) | 10000 | 1236 (1226-1238) | 6274.2 | 10158.8 | 2.297 | 26904 |

Default-driver gate (Linux, N = 10): poll (epoll) 276304 rt/s against select 188749 rt/s, poll is 46% faster. Both
spreads are below 2%, far smaller than the difference.

### Closed-stream scan

Before every blocking wait `PollDriver` checks all its watchers with `Watcher::isActive()` to find streams closed
while watched, O(watched streams) per tick. To price it, a scratch copy of the driver with that one call removed ran
next to the real driver (Linux, epoll, N = 10000, so 20016 watchers):

| Driver | rt/s median (min-max) | idle CPU-s (10 s) | us/tick |
|---|---|---:|---:|
| poll (epoll) | 36100 (36030-36447) | 0.320 | 3362 |
| poll without scan | 37038 (36567-37362) | 0.194 | 2040 |

The scan costs about 1.3 ms per idle tick at 20000 watchers (about 66 ns per watcher), 39% of the idle tick. Under
load it costs 2.6% of round trips per second, close to the run-to-run spread. The remaining idle cost per tick, about
2 ms at 20000 watchers, sits in `Io\Poll\Context::wait()` itself: a bare loop of 100 ms waits on a context with 20000
idle watchers costs about 1.0 ms (kqueue) to 1.3 ms (epoll) of user CPU per call, against 25 to 50 us with 20
watchers. The wait cost therefore grows with the number of registered watchers on every backend, not only with the
number of ready ones; that is why kqueue and epoll also slow down at N = 10000.

### Observations

- The poll(2) backend scales linearly with watched descriptors, as poll(2) does, and is slower than select from
  N = 400 upwards on both platforms. On macOS it fails outright above about 10240 descriptors (`poll()` returns
  `EINVAL` when nfds exceeds `OPEN_MAX`), which surfaces as `FailedPollWaitException` code 3 (`ERROR_INVALID`) out of
  the loop.
- select is already 2.5x (macOS) to 4x (Linux) slower than kqueue or epoll at N = 400, and cannot go further.
- The idle phase never woke an idle callback, and no run stalled.

## Limits of the method

- Single process: both ends of every connection, the client and the server work, share one core and one loop. A real
  server only holds the server half; client-side work inflates the per-tick costs compared with production.
- Loopback only: no network latency, no packet loss, no NIC interrupts. The figures are loop overhead, not network
  performance.
- The Linux numbers come from an arm64 container in Docker Desktop's VM on a Mac, sharing the host with other jobs.
  It is not a production Linux box: kernel, CPU and scheduler differ, and absolute numbers will too. The relative
  ordering of drivers is the useful part.
- One-byte ping-pong exercises the dispatch path only; it says nothing about large writes or TLS.
- Latency samples are taken in PHP with `hrtime()` inside the callbacks, so they include callback scheduling in the
  loop, which is what an application sees.
