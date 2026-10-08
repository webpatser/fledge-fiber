# Local benchmark: fledge-mariadb vs pdo_mysql vs pdo_mysql + fiberio (reused and prepare per call) vs laravel stock vs native-driver

Docker 11.8.9-MariaDB-ubu2404 (127.0.0.1:13307, TCP for all columns), run with `php -d extension=<path>/fiberio.so bench/database/mysql_driver_bench_local.php --scale=0.2 --runs=5` on macOS (PHP 8.5.11). native-driver (fledge-mariadb-native, NativePdoPool of 32 pdo_mysql PDOs on fiberio) and laravel stock (blocking stock Illuminate connection) go through a Laravel Connection; the other columns are raw PDO. The concurrency cases run laravel stock sequentially.

```
Transport check
  fledge-mariadb    : tcp://127.0.0.1:13307 (FledgeMySqlPdo, pool 32)
  pdo_mysql         : 127.0.0.1 via TCP/IP, port 13307
  pdo_mysql+fiberio : 127.0.0.1 via TCP/IP, port 13307, 32 PDO connections, RevoltWaiter (also the prepare-per-call column)
  laravel stock     : Illuminate\Database\MariaDbConnection (mariadb driver), 127.0.0.1 via TCP/IP, port 13307, one blocking PDO, no fiberio, no pool
  native-driver     : fledge-mariadb-native, 127.0.0.1 via TCP/IP, port 13307, pool 32, Native\RevoltWaiter, via Laravel Connection
  server            : 11.8.9-MariaDB-ubu2404
  php               : 8.5.11, fiberio 0.1.0, runs=5, scale=0.2
```

| case | driver | rows | wall s | cpu s (user+sys) | cpu us/row | peak mem MB |
|---|---|---:|---:|---:|---:|---:|
| bulk tk_raw_json (2000) raw PDO | fledge | 2000 | 0.020 | 0.019 | 9.66 | 4.5 |
| bulk tk_raw_json (2000) raw PDO | pdo_mysql | 2000 | 0.010 | 0.002 | 0.98 | 8.0 |
| bulk tk_raw_json (2000) raw PDO | pdo_mysql+fiberio | 2000 | 0.010 | 0.003 | 1.52 | 8.0 |
| bulk tk_raw_json (2000) raw PDO | pdo_mysql+fiberio (prepare per call) | 2000 | 0.010 | 0.003 | 1.45 | 8.0 |
| bulk tk_raw_json (2000) raw PDO | laravel stock | 2000 | 0.010 | 0.002 | 0.97 | 8.1 |
| bulk tk_raw_json (2000) raw PDO | native-driver | 2000 | 0.011 | 0.003 | 1.61 | 8.1 |
| bulk koop_document_metadata (20000) raw PDO | fledge | 20000 | 0.133 | 0.131 | 6.55 | 9.8 |
| bulk koop_document_metadata (20000) raw PDO | pdo_mysql | 20000 | 0.009 | 0.003 | 0.14 | 10.6 |
| bulk koop_document_metadata (20000) raw PDO | pdo_mysql+fiberio | 20000 | 0.007 | 0.003 | 0.16 | 10.6 |
| bulk koop_document_metadata (20000) raw PDO | pdo_mysql+fiberio (prepare per call) | 20000 | 0.007 | 0.003 | 0.16 | 10.6 |
| bulk koop_document_metadata (20000) raw PDO | laravel stock | 20000 | 0.007 | 0.003 | 0.17 | 11.4 |
| bulk koop_document_metadata (20000) raw PDO | native-driver | 20000 | 0.007 | 0.004 | 0.19 | 11.4 |
| bulk feed_items (10000) raw PDO | fledge | 10000 | 0.112 | 0.111 | 11.11 | 8.5 |
| bulk feed_items (10000) raw PDO | pdo_mysql | 10000 | 0.013 | 0.004 | 0.43 | 11.3 |
| bulk feed_items (10000) raw PDO | pdo_mysql+fiberio | 10000 | 0.013 | 0.005 | 0.54 | 11.3 |
| bulk feed_items (10000) raw PDO | pdo_mysql+fiberio (prepare per call) | 10000 | 0.013 | 0.005 | 0.53 | 11.3 |
| bulk feed_items (10000) raw PDO | laravel stock | 10000 | 0.014 | 0.005 | 0.48 | 11.7 |
| bulk feed_items (10000) raw PDO | native-driver | 10000 | 0.014 | 0.006 | 0.63 | 11.7 |
| point feed_items (1000) raw PDO | fledge | 1000 | 0.269 | 0.161 | 161.26 | 0.1 |
| point feed_items (1000) raw PDO | pdo_mysql | 1000 | 0.158 | 0.010 | 10.19 | 0.0 |
| point feed_items (1000) raw PDO | pdo_mysql+fiberio | 1000 | 0.162 | 0.024 | 24.35 | 0.1 |
| point feed_items (1000) raw PDO | pdo_mysql+fiberio (prepare per call) | 1000 | 0.324 | 0.050 | 50.36 | 0.1 |
| point feed_items (1000) raw PDO | laravel stock | 1000 | 0.319 | 0.026 | 25.60 | 0.0 |
| point feed_items (1000) raw PDO | native-driver | 1000 | 0.335 | 0.061 | 60.76 | 0.1 |
| concurrency 32 fibers x 2 range queries (100 rows) | fledge | 6400 | 0.088 | 0.088 | 13.67 | 1.2 |
| concurrency 32 fibers x 2 range queries (100 rows) | pdo_mysql | 6400 | 0.033 | 0.005 | 0.72 | 0.2 |
| concurrency 32 fibers x 2 range queries (100 rows) | pdo_mysql+fiberio | 6400 | 0.009 | 0.007 | 1.02 | 1.7 |
| concurrency 32 fibers x 2 range queries (100 rows) | pdo_mysql+fiberio (prepare per call) | 6400 | 0.009 | 0.007 | 1.13 | 1.6 |
| concurrency 32 fibers x 2 range queries (100 rows) | laravel stock (sequential) | 6400 | 0.030 | 0.005 | 0.72 | 0.2 |
| concurrency 32 fibers x 2 range queries (100 rows) | native-driver | 6400 | 0.009 | 0.007 | 1.15 | 1.7 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | fledge | 32 | 0.057 | 0.007 | 218.00 | 0.8 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql | 32 | 1.710 | 0.007 | 222.84 | 0.1 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql+fiberio | 32 | 0.058 | 0.007 | 206.78 | 1.5 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql+fiberio (prepare per call) | 32 | 0.054 | 0.002 | 65.66 | 1.5 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | laravel stock (sequential) | 32 | 1.731 | 0.008 | 250.69 | 0.0 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | native-driver | 32 | 0.058 | 0.007 | 225.16 | 1.6 |

native-driver relative to the other columns:

| case | native / against | wall x | cpu x |
|---|---|---:|---:|
| bulk tk_raw_json (2000) raw PDO | native / laravel stock | 1.05 | 1.65 |
| bulk tk_raw_json (2000) raw PDO | native / pdo_mysql+fiberio (prepare per call) | 1.01 | 1.11 |
| bulk tk_raw_json (2000) raw PDO | native / pdo_mysql+fiberio | 1.02 | 1.06 |
| bulk koop_document_metadata (20000) raw PDO | native / laravel stock | 1.02 | 1.08 |
| bulk koop_document_metadata (20000) raw PDO | native / pdo_mysql+fiberio (prepare per call) | 1.07 | 1.14 |
| bulk koop_document_metadata (20000) raw PDO | native / pdo_mysql+fiberio | 1.07 | 1.20 |
| bulk feed_items (10000) raw PDO | native / laravel stock | 1.01 | 1.31 |
| bulk feed_items (10000) raw PDO | native / pdo_mysql+fiberio (prepare per call) | 1.05 | 1.20 |
| bulk feed_items (10000) raw PDO | native / pdo_mysql+fiberio | 1.10 | 1.18 |
| point feed_items (1000) raw PDO | native / laravel stock | 1.05 | 2.37 |
| point feed_items (1000) raw PDO | native / pdo_mysql+fiberio (prepare per call) | 1.03 | 1.21 |
| point feed_items (1000) raw PDO | native / pdo_mysql+fiberio | 2.06 | 2.49 |
| concurrency 32 fibers x 2 range queries (100 rows) | native / laravel stock | 0.29 | 1.60 |
| concurrency 32 fibers x 2 range queries (100 rows) | native / pdo_mysql+fiberio (prepare per call) | 1.00 | 1.02 |
| concurrency 32 fibers x 2 range queries (100 rows) | native / pdo_mysql+fiberio | 1.01 | 1.13 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | native / laravel stock | 0.03 | 0.90 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | native / pdo_mysql+fiberio (prepare per call) | 1.07 | 3.43 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | native / pdo_mysql+fiberio | 1.01 | 1.09 |

Notes: native-driver and laravel stock go through Connection::select(); the other columns are raw PDO. pdo_mysql+fiberio (prepare per call) differs from pdo_mysql+fiberio only in the point case (new prepared statement per lookup); its bulk and concurrency cases use the same code path as pdo_mysql+fiberio (every raw PDO column prepares per call there) and are measured again. laravel stock is blocking, so its concurrency cases run sequentially.
