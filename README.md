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

Available database drivers: `fledge-mysql`, `fledge-mariadb`, `fledge-pgsql`

## Driver option support

The drivers aim for config parity with Laravel's stock drivers. Everything below reflects what actually reaches the wire; options that cannot be supported fail loudly or are documented here rather than silently ignored.

### Database

Honored beyond the basics: Postgres `sslcert`/`sslkey`/`sslrootcert` and `keepalives*` (libpq connection string), Postgres session settings (`isolation_level`, `timezone`, `search_path`/`schema`, `synchronous_commit`, `charset`) via libpq startup `-c` options so `DISCARD ALL` on pool checkout restores instead of wipes them, MySQL/MariaDB TLS via `Pdo\Mysql::ATTR_SSL_CA`/`ATTR_SSL_CAPATH`/`ATTR_SSL_CERT`/`ATTR_SSL_KEY`/`ATTR_SSL_CIPHER`/`ATTR_SSL_VERIFY_SERVER_CERT` in `options` (TLS is skipped for unix sockets), `ATTR_INIT_COMMAND` (runs on every new pooled connection), and per-connection `isolation_level`/`timezone` on MySQL through `SessionInitializingConnector`.

Explicitly unsupported PDO attributes: `ATTR_CASE` (server column names always), `ATTR_ERRMODE` (the shim always throws, Laravel's default), `ATTR_ORACLE_NULLS`, `ATTR_STRINGIFY_FETCHES` (native types from the wire), `ATTR_EMULATE_PREPARES` (statements are always server-prepared), `ATTR_PERSISTENT` (pooling replaces it).

MySQL 5.7: there is no live server-version probe, so set `'version' => '5.7'` in the connection config to get the `NO_AUTO_CREATE_USER` strict mode string.

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
