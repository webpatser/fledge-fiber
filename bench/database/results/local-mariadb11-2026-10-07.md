# Local benchmark: fledge-mariadb vs pdo_mysql vs pdo_mysql + fiberio

Docker mariadb:11 (fledge-fiber docker-compose, 127.0.0.1:13307, TCP for all three), tables from `seed_local_mariadb.php`, run with
`php -d extension=<scratch>/modules/fiberio.so bench/database/mysql_driver_bench_local.php --scale=0.2 --runs=5` on macOS (PHP 8.5.11 NTS).
Raw PDO level only (no Laravel app). fiberio column: 32 real PDO connections, one per fiber, `FiberIo\enable(new FiberIo\RevoltWaiter)`; its bulk and point cases run inside one fiber.
Scaled down from host-2 (`host-2-2026-10-07.md`), which runs over a unix socket on production data.

```
Transport check
  fledge-mariadb    : tcp://127.0.0.1:13307 (FledgeMySqlPdo, pool 32)
  pdo_mysql         : 127.0.0.1 via TCP/IP, port 13307
  pdo_mysql+fiberio : 127.0.0.1 via TCP/IP, port 13307, 32 PDO connections, RevoltWaiter
  server            : 11.8.9-MariaDB-ubu2404
  php               : 8.5.11, fiberio 0.1.0, runs=5, scale=0.2
```

| case | driver | rows | wall s | cpu s (user+sys) | cpu us/row | peak mem MB |
|---|---|---:|---:|---:|---:|---:|
| bulk tk_raw_json (2000) raw PDO | fledge | 2000 | 0.021 | 0.019 | 9.65 | 4.5 |
| bulk tk_raw_json (2000) raw PDO | pdo_mysql | 2000 | 0.011 | 0.002 | 1.11 | 8.0 |
| bulk tk_raw_json (2000) raw PDO | pdo_mysql+fiberio | 2000 | 0.010 | 0.003 | 1.61 | 8.0 |
| bulk koop_document_metadata (20000) raw PDO | fledge | 20000 | 0.132 | 0.130 | 6.49 | 9.8 |
| bulk koop_document_metadata (20000) raw PDO | pdo_mysql | 20000 | 0.008 | 0.003 | 0.13 | 10.6 |
| bulk koop_document_metadata (20000) raw PDO | pdo_mysql+fiberio | 20000 | 0.007 | 0.004 | 0.19 | 10.6 |
| bulk feed_items (10000) raw PDO | fledge | 10000 | 0.114 | 0.112 | 11.23 | 8.5 |
| bulk feed_items (10000) raw PDO | pdo_mysql | 10000 | 0.013 | 0.004 | 0.43 | 11.3 |
| bulk feed_items (10000) raw PDO | pdo_mysql+fiberio | 10000 | 0.011 | 0.005 | 0.55 | 11.3 |
| point feed_items (1000) raw PDO | fledge | 1000 | 0.275 | 0.160 | 159.57 | 0.1 |
| point feed_items (1000) raw PDO | pdo_mysql | 1000 | 0.161 | 0.010 | 10.09 | 0.0 |
| point feed_items (1000) raw PDO | pdo_mysql+fiberio | 1000 | 0.169 | 0.024 | 24.19 | 0.1 |
| concurrency 32 fibers x 2 range queries (100 rows) | fledge | 6400 | 0.090 | 0.089 | 13.91 | 1.2 |
| concurrency 32 fibers x 2 range queries (100 rows) | pdo_mysql | 6400 | 0.033 | 0.005 | 0.72 | 0.2 |
| concurrency 32 fibers x 2 range queries (100 rows) | pdo_mysql+fiberio | 6400 | 0.008 | 0.006 | 1.02 | 1.6 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | fledge | 32 | 0.058 | 0.009 | 277.03 | 0.8 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql | 32 | 1.757 | 0.006 | 181.88 | 0.1 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql+fiberio | 32 | 0.057 | 0.006 | 188.47 | 1.5 |

