# Fledge Fiber

Non-blocking async drivers for the [Fledge framework](https://github.com/webpatser/fledge). PHP 8.5 only.

Provides fiber-based database (MySQL, MariaDB, PostgreSQL), Redis, HTTP, WebSocket, filesystem, and parallel processing drivers that integrate seamlessly with Laravel's service container.

## Requirements

- PHP 8.5+
- [revolt/event-loop](https://github.com/revoltphp/event-loop) ^1.0

## Installation

```bash
composer require webpatser/fledge-fiber
```

The `FiberServiceProvider` is auto-discovered. No manual registration needed.

## Configuration

Set drivers in your `.env`:

```env
DB_CONNECTION=fledge-mysql
REDIS_CLIENT=fledge
```

Available database drivers: `fledge-mysql`, `fledge-mariadb`, `fledge-pgsql`, plus the pdo_mysql based `fledge-mysql-native` and `fledge-mariadb-native` (see below)

## Driver option support

The drivers aim for config parity with Laravel's stock drivers. Everything below reflects what actually reaches the wire; options that cannot be supported fail loudly or are documented here rather than silently ignored.

### Database

Honored beyond the basics: Postgres `sslcert`/`sslkey`/`sslrootcert` and `keepalives*` (libpq connection string), Postgres session settings (`isolation_level`, `timezone`, `search_path`/`schema`, `synchronous_commit`, `charset`) via libpq startup `-c` options so `DISCARD ALL` on pool checkout restores instead of wipes them, MySQL/MariaDB TLS via `Pdo\Mysql::ATTR_SSL_CA`/`ATTR_SSL_CAPATH`/`ATTR_SSL_CERT`/`ATTR_SSL_KEY`/`ATTR_SSL_CIPHER`/`ATTR_SSL_VERIFY_SERVER_CERT` in `options` (TLS is skipped for unix sockets), `ATTR_INIT_COMMAND` (runs on every new pooled connection), and per-connection `isolation_level`/`timezone` on MySQL through `SessionInitializingConnector`.

Explicitly unsupported PDO attributes: `ATTR_CASE` (server column names always), `ATTR_ERRMODE` (the shim always throws, Laravel's default), `ATTR_ORACLE_NULLS`, `ATTR_STRINGIFY_FETCHES` (native types from the wire), `ATTR_EMULATE_PREPARES` (statements are always server-prepared), `ATTR_PERSISTENT` (pooling replaces it).

MySQL 5.7: there is no live server-version probe, so set `'version' => '5.7'` in the connection config to get the `NO_AUTO_CREATE_USER` strict mode string.

Transactions on `fledge-mysql`, `fledge-mariadb` and `fledge-pgsql` are pinned per Laravel connection, not per fiber. While one fiber (or the main context) has a transaction open, a query, prepared-statement execute, begin, commit or rollback from any other fiber on that connection throws a `LogicException` instead of silently running inside it. Give concurrent fibers separate connections, or use the native drivers below, which keep transactions per fiber.

### Native MySQL/MariaDB drivers (`fledge-mysql-native`, `fledge-mariadb-native`)

A pool of real `pdo_mysql` connections running on [php-fiberio](https://github.com/webpatser/php-fiberio), one lease per fiber. Fiberio makes the stock PDO socket I/O yield to the Revolt event loop, so you get Laravel's own connectors, TLS, session settings and error shapes without the userland wire protocol of `fledge-mysql` / `fledge-mariadb`. A fiber takes a connection for the duration of a query (or a whole transaction, or a `cursor()` until its generator ends) and gives it back afterwards. A fiber that finds the pool empty waits for a free lease, and fails with a `RuntimeException` after `pool_wait_timeout` (default 30s).

Requirements:

- `pie install webpatser/php-fiberio` (NTS builds of PHP only; the extension is v0.x).
- The native drivers need php-fiberio with the error-handling fix: php-fiberio 0.1.0 or later. Earlier builds let warnings in other fibers throw `PDOException` while a connect is in flight.
- Without the extension the connector throws a `RuntimeException` naming that command. Set `'fiberio' => 'optional'` on the connection to fall back to blocking PDO with one logged warning instead.

```php
// config/database.php
'connections' => [
    'mariadb-native' => [
        'driver' => 'fledge-mariadb-native', // or 'fledge-mysql-native'
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'laravel'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'pool_size' => 32,         // max real connections (default 32)
        'pool_idle_timeout' => 60, // seconds before an idle connection is closed (default 60)
        'pool_wait_timeout' => 30, // seconds a fiber waits for a free lease (default 30)
        'fiberio' => 'optional',   // optional: fall back to blocking PDO when the extension is missing
    ],
],
```

`PDO::ATTR_PERSISTENT` is rejected with an `InvalidArgumentException`: fiberio routes persistent streams to the blocking transport, and the pool replaces them.

#### Caveats and migration notes from `fledge-mariadb` / `fledge-mysql`

- **Fiberio is process-wide.** Once enabled, every PHP stream client is affected, not only PDO. A stream shared by two fibers (Predis, SMTP transports, the Guzzle stream handler) now throws "stream is in use by another fiber" instead of blocking. Give each fiber its own client, or serialize access.
- **TLS needs a cipher or CA.** Set `PDO::MYSQL_ATTR_SSL_CIPHER` (or a CA via `ATTR_SSL_CA`) in `options`. Unlike `fledge-mariadb`, `ATTR_SSL_VERIFY_SERVER_CERT => false` on its own connects in plain text.
- **RefreshDatabase** wraps only the main context. Other fibers use other leases and do not see the test transaction. A child fiber that waits on rows locked by the test transaction can hit error 1205 (lock wait timeout). Queue and event after-commit hooks bypass per-fiber routing under RefreshDatabase.
- **Exhausted pool.** A fiber waiting on a full pool (for example `pool_size => 1` with a second fiber) fails with a `RuntimeException` after `pool_wait_timeout`; the message names leaked leases.
- **Pool size is per pool.** `pool_size` applies to each pool (write, read and direct), so a read/write split can open 2 to 3 times that many connections.
- **Octane.** The `DisconnectFromDatabases` listener closes the pool, which fails open transactions of other in-flight requests when running a fiber server.
- **Child fibers and transactions.** A child fiber started inside a transaction runs on a separate lease, outside that transaction, so it cannot see the uncommitted rows (and with a small pool it waits until `pool_wait_timeout`).
- **`afterCommit` callbacks** are routed per fiber. A fiber that is not inside its own transaction runs them immediately, even while the main context has a native transaction open (its writes already autocommitted on another connection). A pgsql or `fledge-mariadb` transaction nested inside a fiber's native transaction gets its callbacks deferred to the native commit, and they are not dropped if only the inner transaction rolls back (stock Laravel attaches them to the innermost transaction). Rare, cross-connection only.
- **Custom transactions manager.** Fledge wraps the `db.transactions` binding only when it is Laravel's own `DatabaseTransactionsManager`. An app's own subclass stays bound as is: native connections still keep their transactions and `DB::afterCommit()` callbacks per fiber, but callbacks registered on the container from a fiber (after-commit queued jobs, broadcasts) go to that subclass unwrapped.
- **Shared connection state.** `pretend()` and `recordsModified` live on the Laravel connection object and are shared by all fibers.
- **Bare `DB::getPdo()`** keeps its lease until that fiber's next query ends. Prefer the query builder, or release it by running a query.
- **Unbuffered queries:** fetches are not ownership-checked, so only the fiber that ran the query may fetch from it.
- **No benefit under FPM** without an event loop: requests run one at a time, so the pool just adds overhead.
- **Use a socket or IP address, not a DNS hostname.** Name resolution blocks the whole process while connecting.
- A fiberio crash is a C-level crash and takes the worker down. Keep it to workers you can restart (Octane, queue workers) and treat the v0.x version as such.

`fledge-mysql` and `fledge-mariadb` keep their wire-protocol implementation and stay available.

### Redis

Supported: `scheme` (tcp/tls/rediss/unix), `host`, `port`, `path` (unix socket, predis and phpredis styles), `username`/`password` (two-arg AUTH), `database`, `timeout`, `read_timeout` (values at or below 0 wait forever), `context` ssl options (`peer_name`, `verify_peer`, `verify_peer_name`, `cafile`, `capath`, `verify_depth`, `ciphers`, `local_cert`/`local_pk`/`passphrase`, `security_level`, `peer_fingerprint`), `prefix` (phpredis OPT_PREFIX semantics, including pub/sub channels), `name` (CLIENT SETNAME), `tcp_keepalive` (best effort, needs ext-sockets), `command_retries`, `max_retries`, `retry_interval`, `backoff_algorithm`/`backoff_base`/`backoff_cap`. Cluster seeds inherit TLS, auth, timeouts, and retry policy.

Tolerated without effect: `scan` (SCAN MATCH patterns are not auto-prefixed, matching phpredis defaults), `persistent`/`persistent_id` (connections are long-lived per worker), `compression_level` without compression.

Rejected loudly with `UnsupportedRedisOptionException`: `serializer` and `compression` (data would be unreadable across clients), `pack_ignore_numbers`, `replication => sentinel` (use a direct connection or predis), cluster `failover => distribute`/`distribute_slaves` (reads always go to a master), `options.cluster` other than `redis`.

### HTTP client handler

`FledgeHandler` is a Guzzle handler backed by the async HTTP client, registered globally by `FiberHttpServiceProvider` (disabled under PHPUnit); opt out at runtime with `Factory::globalHandler(null)`.

Supported request options: `timeout`, `connect_timeout`, `version` (1.0/1.1/2 with ALPN and 1.1 fallback), `verify` (true/false/CA file or dir), `cert`, `ssl_key`, `crypto_method`, `proxy` (string or array form including `no`; `http://` via CONNECT and `socks5://`), `decode_content`, `sink` (path, resource, PSR-7 stream), `stream`, `on_headers`, `on_stats` (curl-shaped handler stats), `delay`, `allow_redirects` (all Guzzle sub-options, handled by RedirectMiddleware), plus everything Guzzle middleware implements above the handler. Transport failures reject as Guzzle exception types, so `Illuminate\Http\Client\ConnectionException` and the `ConnectionFailed` event work.

Known limitations: no `ntlm` auth, `progress`, `debug`, `force_ip_resolve`, Expect 100-continue handling, or raw curl options; no `https://`-scheme proxies; plain-HTTP proxying always tunnels via CONNECT; `namelookup_time` is always 0 in handler stats.

## What's included

| Module | Namespace | Description |
|--------|-----------|-------------|
| **Core** | `Fledge\Async` | Future, async/await, cancellation, pipelines |
| **Stream** | `Fledge\Async\Stream` | Non-blocking byte streams, sockets, TLS |
| **Database** | `Fledge\Async\Database` | MySQL, MariaDB, PostgreSQL wire protocols |
| **Redis** | `Fledge\Async\Redis` | RESP protocol, pub/sub, TLS |
| **HTTP** | `Fledge\Async\Http` | HTTP/1.1 + HTTP/2 client and server |
| **WebSocket** | `Fledge\Async\WebSocket` | WebSocket client and server |
| **File** | `Fledge\Async\File` | Non-blocking filesystem I/O |
| **Parallel** | `Fledge\Async\Parallel` | Multi-process worker pools |
| **DNS** | `Fledge\Async\Dns` | Async DNS resolution |
| **Cache** | `Fledge\Async\Cache` | Cache interfaces + local implementations |
| **Sync** | `Fledge\Async\Sync` | Mutexes, semaphores, barriers |
| **Process** | `Fledge\Async\Process` | OS process management |

The Laravel integration layer lives under `Fledge\Fiber\` and bridges async drivers to Laravel's database, Redis, HTTP, and Livewire systems.

## PHP 8.5 Features

This library requires PHP 8.5 and uses:

- `#[\NoDiscard]` on Future-returning methods
- `clone()` with property overrides for immutable configs
- `readonly class` for value objects (76 classes)
- Typed class constants
- First-class callable syntax throughout

## Versioning

Follows Fledge versioning: `v13.x.y.z` where the first three digits match the Laravel version and the fourth is the fledge-fiber patch level.

## License

Apache-2.0
