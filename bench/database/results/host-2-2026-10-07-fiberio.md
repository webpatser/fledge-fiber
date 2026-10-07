Transport check
  configured unix_socket : /run/mysqld/mysqld.sock
  fledge-mariadb         : unix:///run/mysqld/mysqld.sock (class Fledge\Fiber\Database\Pdo\FledgeMySqlPdo, server @@socket=/run/mysqld/mysqld.sock)
  pdo_mysql              : Localhost via UNIX socket (server @@socket=/run/mysqld/mysqld.sock), same path /run/mysqld/mysqld.sock
  pdo_mysql+fiberio      : Localhost via UNIX socket (server @@socket=/run/mysqld/mysqld.sock), 32 PDO connections, RevoltWaiter, fiberio 0.1.0
  server                 : 12.3.3-MariaDB-ubu2404
  php                    : 8.5.11, runs=5, scale=1

| case | driver | rows | wall s | cpu s (user+sys) | cpu us/row | peak mem MB |
|---|---|---:|---:|---:|---:|---:|
| bulk tk_raw_json (10000) laravel select() | fledge | 10000 | 0.089 | 0.089 | 8.94 | 16.9 |
| bulk tk_raw_json (10000) laravel select() | pdo_mysql | 10000 | 0.021 | 0.018 | 1.79 | 26.6 |
| bulk tk_raw_json (10000) laravel select() | pdo_mysql+fiberio | 10000 | 0.019 | 0.019 | 1.87 | 26.7 |
| bulk tk_raw_json (10000) raw PDO | fledge | 10000 | 0.088 | 0.088 | 8.81 | 16.5 |
| bulk tk_raw_json (10000) raw PDO | pdo_mysql | 10000 | 0.019 | 0.016 | 1.59 | 26.3 |
| bulk tk_raw_json (10000) raw PDO | pdo_mysql+fiberio | 10000 | 0.019 | 0.018 | 1.81 | 26.3 |
| bulk koop_document_metadata (100000) laravel select() | fledge | 100000 | 0.486 | 0.485 | 4.85 | 43.7 |
| bulk koop_document_metadata (100000) laravel select() | pdo_mysql | 100000 | 0.041 | 0.034 | 0.34 | 46.4 |
| bulk koop_document_metadata (100000) laravel select() | pdo_mysql+fiberio | 100000 | 0.042 | 0.035 | 0.35 | 46.4 |
| bulk koop_document_metadata (100000) raw PDO | fledge | 100000 | 0.475 | 0.475 | 4.75 | 39.9 |
| bulk koop_document_metadata (100000) raw PDO | pdo_mysql | 100000 | 0.036 | 0.028 | 0.28 | 42.6 |
| bulk koop_document_metadata (100000) raw PDO | pdo_mysql+fiberio | 100000 | 0.034 | 0.028 | 0.28 | 42.6 |
| bulk feed_items (50000) laravel select() | fledge | 50000 | 0.427 | 0.426 | 8.53 | 74.9 |
| bulk feed_items (50000) laravel select() | pdo_mysql | 50000 | 0.085 | 0.064 | 1.28 | 73.4 |
| bulk feed_items (50000) laravel select() | pdo_mysql+fiberio | 50000 | 0.085 | 0.070 | 1.39 | 73.4 |
| bulk feed_items (50000) raw PDO | fledge | 50000 | 0.416 | 0.415 | 8.30 | 73.0 |
| bulk feed_items (50000) raw PDO | pdo_mysql | 50000 | 0.080 | 0.059 | 1.18 | 71.5 |
| bulk feed_items (50000) raw PDO | pdo_mysql+fiberio | 50000 | 0.077 | 0.062 | 1.24 | 71.5 |
| point feed_items (5000) laravel select() | fledge | 5000 | 1.275 | 1.259 | 251.89 | 4.4 |
| point feed_items (5000) laravel select() | pdo_mysql | 5000 | 0.310 | 0.174 | 34.75 | 4.4 |
| point feed_items (5000) laravel select() | pdo_mysql+fiberio | 5000 | 0.353 | 0.286 | 57.19 | 4.4 |
| point feed_items (5000) raw PDO | fledge | 5000 | 0.675 | 0.663 | 132.54 | 0.1 |
| point feed_items (5000) raw PDO | pdo_mysql | 5000 | 0.099 | 0.040 | 8.06 | 0.0 |
| point feed_items (5000) raw PDO | pdo_mysql+fiberio | 5000 | 0.113 | 0.085 | 16.94 | 0.1 |
| concurrency 32 fibers x 10 range queries (100 rows) | fledge | 32000 | 0.334 | 0.334 | 10.44 | 2.2 |
| concurrency 32 fibers x 10 range queries (100 rows) | pdo_mysql | 32000 | 0.067 | 0.039 | 1.21 | 0.5 |
| concurrency 32 fibers x 10 range queries (100 rows) | pdo_mysql+fiberio | 32000 | 0.041 | 0.040 | 1.25 | 1.8 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | fledge | 32 | 0.056 | 0.007 | 204.44 | 0.8 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql | 32 | 1.609 | 0.005 | 162.34 | 0.1 |
| concurrency 32 fibers x SELECT SLEEP(0.05) | pdo_mysql+fiberio | 32 | 0.052 | 0.002 | 73.44 | 1.4 |

