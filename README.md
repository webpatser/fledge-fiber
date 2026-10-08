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

Available database drivers:

| Driver | Database | Implementation |
|---|---|---|
| `fledge-mysql`, `fledge-mariadb` | MySQL, MariaDB | MySQL wire protocol in PHP |
| `fledge-pgsql` | PostgreSQL | PostgreSQL driver in PHP |
| `fledge-mysql-native`, `fledge-mariadb-native` | MySQL, MariaDB | `pdo_mysql` made fiber-aware by [php-fiberio](https://github.com/webpatser/php-fiberio) (see [below](#native-mysqlmariadb-drivers-fledge-mysql-native-fledge-mariadb-native)) |

## Driver option support

The drivers aim for config parity with Laravel's stock drivers. Everything below reflects what actually reaches the wire; options that cannot be supported fail loudly or are documented here rather than silently ignored.

### Database

Honored beyond the basics: Postgres `sslcert`/`sslkey`/`sslrootcert` and `keepalives*` (libpq connection string), Postgres session settings (`isolation_level`, `timezone`, `search_path`/`schema`, `synchronous_commit`, `charset`) via libpq startup `-c` options so `DISCARD ALL` on pool checkout restores instead of wipes them, MySQL/MariaDB TLS via `Pdo\Mysql::ATTR_SSL_CA`/`ATTR_SSL_CAPATH`/`ATTR_SSL_CERT`/`ATTR_SSL_KEY`/`ATTR_SSL_CIPHER`/`ATTR_SSL_VERIFY_SERVER_CERT` in `options` (TLS is skipped for unix sockets), `ATTR_INIT_COMMAND` (runs on every new pooled connection), and per-connection `isolation_level`/`timezone` on MySQL through `SessionInitializingConnector`.

Explicitly unsupported PDO attributes: `ATTR_CASE` (server column names always), `ATTR_ERRMODE` (the shim always throws, Laravel's default), `ATTR_ORACLE_NULLS`, `ATTR_STRINGIFY_FETCHES` (native types from the wire), `ATTR_EMULATE_PREPARES` (statements are always server-prepared), `ATTR_PERSISTENT` (pooling replaces it).

MySQL 5.7: there is no live server-version probe, so set `'version' => '5.7'` in the connection config to get the `NO_AUTO_CREATE_USER` strict mode string.

Transactions on `fledge-mysql`, `fledge-mariadb` and `fledge-pgsql` are pinned per Laravel connection, not per fiber. While one fiber (or the main context) has a transaction open, a query, prepared-statement execute, begin, commit or rollback from any other fiber on that connection throws a `LogicException` instead of silently running inside it. Give concurrent fibers separate connections, or use the native drivers below, which keep transactions per fiber.

### Native MySQL/MariaDB drivers (`fledge-mysql-native`, `fledge-mariadb-native`)

A pool of real `pdo_mysql` connections running on [php-fiberio](https://github.com/webpatser/php-fiberio), one lease per fiber. Fiberio makes the stock PDO socket I/O yield to the Revolt event loop, so you get Laravel's own connectors, TLS, session settings and error shapes without the userland wire protocol. A fiber takes a connection for the duration of a query (or a whole transaction, or a `cursor()` until its generator ends) and gives it back afterwards. A fiber that finds the pool empty waits for a free lease, and fails with a `RuntimeException` after `pool_wait_timeout`.

#### When to use which driver

| | `fledge-mariadb` / `fledge-mysql` | `fledge-mariadb-native` / `fledge-mysql-native` |
|---|---|---|
| Wire protocol | Implemented in PHP | Stock `pdo_mysql` (C) |
| Extra requirements | None | `php-fiberio` 0.1.0+ (0.2.0+ for the hooks below), NTS PHP only |
| Transactions | Pinned per connection; other fibers get a `LogicException` | Per fiber, each on its own lease |
| CPU per row, bulk read (10,000 rows) | 11.11 us | 0.63 us |
| CPU per row, bulk read (20,000 rows) | 6.55 us | 0.19 us |
| Wall time, 32 fibers x `SELECT SLEEP(0.05)` | 0.057 s | 0.058 s |

Both overlap concurrent I/O equally well; the native drivers cost far less CPU per row on large reads. Numbers are from `bench/database/results/local-mariadb11-2026-10-08.md` (MariaDB 11.8, PHP 8.5.11, local TCP, scale 0.2, 5 runs). On single-row point lookups the native driver spent 60.76 us of CPU per row against 161.26 us for `fledge-mariadb`, but more than blocking stock `pdo_mysql` through Laravel (25.60 us), as it adds pool and lease handling.

#### Install fiberio

```bash
pie install webpatser/php-fiberio
```

- NTS builds of PHP only; the extension is v0.x. Source and releases: <https://github.com/webpatser/php-fiberio>.
- php-fiberio 0.1.0 or later is required. Earlier builds let warnings in other fibers throw `PDOException` while a connect is in flight. The hooks below need 0.2.0; with 0.1.0 the driver still works and simply enables the `tcp://`/`unix://` hook.
- Without the extension the connector throws a `RuntimeException` naming the install command. Set `'fiberio' => 'optional'` to fall back to blocking PDO with one logged warning instead.

#### Configuration

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
        'fiberio' => 'optional',   // omit to require the extension
    ],
],
```

`PDO::ATTR_PERSISTENT` is rejected with an `InvalidArgumentException`: fiberio routes persistent streams to the blocking transport, and the pool replaces them.

#### Switching an existing app

1. Install fiberio on every worker host (see above).
2. Change `driver` from `fledge-mariadb` to `fledge-mariadb-native` (or `fledge-mysql` to `fledge-mysql-native`). Host, credentials and charset stay as they are.
3. Review the caveats below, especially TLS and test setup.

To roll back, change `driver` back. `fledge-mysql` and `fledge-mariadb` keep their wire-protocol implementation and stay available.

#### fiberio hooks (`FLEDGE_FIBERIO_HOOKS`)

With php-fiberio 0.2.0 or later the extension can also make `sleep()` and friends, DNS lookups and `ssl://`/`tls://` client streams fiber-aware. The native connector enables them for the whole process through config `fledge-http.fiberio_hooks`, fed by the env var `FLEDGE_FIBERIO_HOOKS`:

```env
FLEDGE_FIBERIO_HOOKS=sleep,dns,ssl   # default: all
```

- Values: a comma list (or array) of `sleep`, `dns`, `ssl`, or `all`. `none`, `off`, `no`, `false`, `0` and the empty string turn every optional hook off (plain `tcp://` and `unix://` stay hooked, which is what the driver needs).
- Unknown names are ignored with one logged warning per process. If no valid name is left, the result is no hooks, never all.
- Precedence: the connection's own `fiberio_hooks` key, then `fledge-http.fiberio_hooks` (env `FLEDGE_FIBERIO_HOOKS`), then `all`.
- fiberio state is process-global and `enable()` runs once, so the first native connection to connect decides the hooks for the whole process. Set it app-wide through the env var rather than per connection.
- Needs php-fiberio 0.2.0. On v0.1.0 the setting is ignored and `enable()` is called without a hooks argument; everything keeps working.
- The setting is read through config, so it survives `php artisan config:cache`. Run `config:cache` again after changing the env var.

#### Caveats and migration notes

**Requirements**
- **TLS needs a cipher or CA.** Set `PDO::MYSQL_ATTR_SSL_CIPHER` (or a CA via `ATTR_SSL_CA`) in `options`. Unlike `fledge-mariadb`, `ATTR_SSL_VERIFY_SERVER_CERT => false` on its own connects in plain text.
- **Use a socket or IP address, or enable the DNS hook.** Without the `dns` hook (php-fiberio 0.1.0, or `FLEDGE_FIBERIO_HOOKS` without `dns`), resolving a hostname blocks the whole process while connecting. With php-fiberio 0.2.0 and the `dns` hook, the lookup runs on a helper thread and only the calling fiber waits.
- **No benefit under FPM** without an event loop: requests run one at a time, so the pool just adds overhead.

**Behaviour**
- **Fiberio is process-wide.** Once enabled, every PHP stream client is affected, not only PDO. A stream shared by two fibers (Predis, SMTP transports, the Guzzle stream handler) now throws "stream is in use by another fiber" instead of blocking. Give each fiber its own client, or serialize access.
- **Child fibers and transactions.** A child fiber started inside a transaction runs on a separate lease, outside that transaction, so it cannot see the uncommitted rows (and with a small pool it waits until `pool_wait_timeout`).
- **`afterCommit` callbacks** are routed per fiber. A fiber that is not inside its own transaction runs them immediately, even while the main context has a native transaction open (its writes already autocommitted on another connection). A pgsql or `fledge-mariadb` transaction nested inside a fiber's native transaction gets its callbacks deferred to the native commit, and they are not dropped if only the inner transaction rolls back (stock Laravel attaches them to the innermost transaction). Rare, cross-connection only.
- **Custom transactions manager.** Fledge wraps the `db.transactions` binding only when it is Laravel's own `DatabaseTransactionsManager`. An app's own subclass stays bound as is: native connections still keep their transactions and `DB::afterCommit()` callbacks per fiber, but callbacks registered on the container from a fiber (after-commit queued jobs, broadcasts) go to that subclass unwrapped.
- **Shared connection state.** `pretend()` and `recordsModified` live on the Laravel connection object and are shared by all fibers.
- **Bare `DB::getPdo()`** keeps its lease until that fiber's next query ends. Prefer the query builder, or release it by running a query.
- **Unbuffered queries:** fetches are not ownership-checked, so only the fiber that ran the query may fetch from it.

**Testing**
- **RefreshDatabase** wraps only the main context. Other fibers use other leases and do not see the test transaction. A child fiber that waits on rows locked by the test transaction can hit error 1205 (lock wait timeout). Queue and event after-commit hooks bypass per-fiber routing under RefreshDatabase.

**Operations**
- **Exhausted pool.** A fiber waiting on a full pool (for example `pool_size => 1` with a second fiber) fails with a `RuntimeException` after `pool_wait_timeout`; the message names leaked leases.
- **Pool size is per pool.** `pool_size` applies to each pool (write, read and direct), so a read/write split can open 2 to 3 times that many connections.
- **Octane.** The `DisconnectFromDatabases` listener closes the pool, which fails open transactions of other in-flight requests when running a fiber server.
- **Crashes.** A fiberio crash is a C-level crash and takes the worker down. Keep it to workers you can restart (Octane, queue workers) and treat the v0.x version as such.

### Redis

Supported: `scheme` (tcp/tls/rediss/unix), `host`, `port`, `path` (unix socket, predis and phpredis styles), `username`/`password` (two-arg AUTH), `database`, `timeout`, `read_timeout` (values at or below 0 wait forever), `context` ssl options (`peer_name`, `verify_peer`, `verify_peer_name`, `cafile`, `capath`, `verify_depth`, `ciphers`, `local_cert`/`local_pk`/`passphrase`, `security_level`, `peer_fingerprint`), `prefix` (phpredis OPT_PREFIX semantics, including pub/sub channels), `name` (CLIENT SETNAME), `tcp_keepalive` (best effort, needs ext-sockets), `command_retries`, `max_retries`, `retry_interval`, `backoff_algorithm`/`backoff_base`/`backoff_cap`. Cluster seeds inherit TLS, auth, timeouts, and retry policy.

Tolerated without effect: `scan` (SCAN MATCH patterns are not auto-prefixed, matching phpredis defaults), `persistent`/`persistent_id` (connections are long-lived per worker), `compression_level` without compression.

Rejected loudly with `UnsupportedRedisOptionException`: `serializer` and `compression` (data would be unreadable across clients), `pack_ignore_numbers`, `replication => sentinel` (use a direct connection or predis), cluster `failover => distribute`/`distribute_slaves` (reads always go to a master), `options.cluster` other than `redis`.

### HTTP client handler

`FledgeHandler` is a Guzzle handler backed by the async HTTP client, registered globally by `FiberHttpServiceProvider` (disabled under PHPUnit); opt out at runtime with `Factory::globalHandler(null)`.

Supported request options: `timeout`, `connect_timeout`, `version` (1.0/1.1/2 with ALPN and 1.1 fallback), `verify` (true/false/CA file or dir), `cert`, `ssl_key`, `crypto_method`, `proxy` (string or array form including `no`; `http://` via CONNECT and `socks5://`), `decode_content`, `sink` (path, resource, PSR-7 stream), `stream`, `on_headers`, `on_stats` (curl-shaped handler stats), `delay`, `allow_redirects` (all Guzzle sub-options, handled by RedirectMiddleware), plus everything Guzzle middleware implements above the handler. Transport failures reject as Guzzle exception types, so `Illuminate\Http\Client\ConnectionException` and the `ConnectionFailed` event work.

Known limitations: no `ntlm` auth, `progress`, `debug`, `force_ip_resolve`, Expect 100-continue handling, or raw curl options; no `https://`-scheme proxies; plain-HTTP proxying always tunnels via CONNECT; `namelookup_time` is always 0 in handler stats.

#### `FledgeGuzzle`: one stack for every Guzzle consumer

`Fledge\Fiber\Http\FledgeGuzzle` hands out Guzzle pieces that run on the Fledge handler instead of curl:

```php
use Fledge\Fiber\Http\FledgeGuzzle;

$stack = FledgeGuzzle::stack();           // HandlerStack: redirects, cookies, http_errors, prepare-body on FledgeHandler
$client = FledgeGuzzle::client(['base_uri' => 'https://api.example.com']);
$limited = FledgeGuzzle::client([], 8);   // at most 8 open connections per host
$factory = FledgeGuzzle::factory(8);      // the shared AsyncClientFactory for that limit
FledgeGuzzle::flush();                    // drop the shared pools (tests)
```

- `stack(?int $perHost = null)` returns a fresh `HandlerStack` each call (stacks are mutable); `client(array $options = [], ?int $perHost = null)` is a `GuzzleHttp\Client` on it, and a `handler` in `$options` wins.
- Connection pools live in one `AsyncClientFactory` per per-host limit, shared by every stack and client, so keep-alive connections are reused across integrations.

#### Third-party integrations (on by default)

`FiberHttpServiceProvider` also puts the HTTP clients of these integrations on the Fledge handler, so their requests suspend only the calling fiber:

| Integration | What changes | Env flag |
|---|---|---|
| Mail | The mail manager is replaced by `FiberMailManager`. HTTP transports (Postmark, Mailgun, Resend, ...) use a Symfony `HttpClientInterface` on the Fledge client. SES and SES v2 get an AWS SDK `http_handler` on the Fledge stack. | `FLEDGE_HTTP_MAIL` |
| Broadcasting | The `pusher` and `reverb` drivers get `client_options.handler` on the Fledge stack. | `FLEDGE_HTTP_BROADCASTING` |
| S3 | The `s3` filesystem driver gets an `http_handler` through the AWS SDK's Guzzle bridge. No-op without `aws/aws-sdk-php`. | `FLEDGE_HTTP_S3` |
| Elasticsearch | PDPhilip's `elasticsearch` database driver is replaced by `FledgeElasticConnection`, built by `ElasticClientFactory`. No-op without `pdphilip/elasticsearch`. | `FLEDGE_HTTP_ELASTICSEARCH` |

**Behaviour change: all four are ON by default after upgrading.** Mail, Pusher/Reverb, S3 and Elasticsearch traffic that used to go through curl, Symfony's client or the AWS default handler now goes through Fledge. Set the flag to `false` to return one integration to its previous client:

```env
FLEDGE_HTTP_MAIL=false
FLEDGE_HTTP_BROADCASTING=false
FLEDGE_HTTP_S3=false
FLEDGE_HTTP_ELASTICSEARCH=false
```

- All integrations are inactive while PHPUnit runs, so application test suites keep the stock managers and HTTP fakes.
- Config lives in `config/fledge-http.php`, merged by the provider (there is no `vendor:publish` tag). To change a value, set the env var or add your own `config/fledge-http.php` with the keys you want to override. It holds scalars only, so it is `config:cache` safe; run `php artisan config:cache` again after changing an env var.
- Per-host connection limits, `fledge-http.pool_per_host.*` (`null` means unlimited): `mail` null, `broadcasting` 8, `s3` null, `elasticsearch` 8. A mailer's own `client.max_host_connections` wins over `pool_per_host.mail`.
- Per-mailer opt-out: `'client' => ['fledge' => false]` in the mailer config keeps that mailer (and its SES handler) on the stock client. Other keys of a mailer's `client` array are passed on as Symfony request options; `max_pending_pushes` is dropped. Without `symfony/http-client` the stock client is used.
- An `http_handler` set on an SES mailer, in `services.ses`, or on an S3 disk, and a `client_options.handler` on a Pusher/Reverb connection, are left alone.
- Extend-order caveat: Pusher/Reverb and S3 are registered right after the broadcast and filesystem managers resolve. An app's own `Broadcast::extend('pusher')` or `Storage::extend('s3')` in a provider `boot()` replaces the Fledge one (last extend wins).
- Elasticsearch moved here from scrpr's `app/Support/Search`. The handler is now passed through `setHttpClientOptions(['handler' => ...])` as well, because `ClientBuilder` rebuilds its Guzzle client when SSL verification is off or a CA bundle is set, and used to lose the handler (and fall back to curl).

#### Symfony HttpClient adapter

`Fledge\Fiber\Http\Symfony\FledgeSymfonyHttpClient` implements Symfony's `HttpClientInterface` on the async client (requires `symfony/http-client`). Requests start when `request()` returns and responses created back to back overlap on the wire. Supported options: `timeout`, `max_duration`, `max_connect_duration`, `verify_peer`, `verify_host`, `cafile`, `capath`, `local_cert`, `local_pk`, `passphrase`, `crypto_method`, `proxy`, `no_proxy`, `max_redirects`, `http_version` (`1.0`, `1.1`, `2`), plus the body and header options Symfony normalizes itself.

- Unsupported options throw `InvalidArgumentException` instead of being ignored: `bindto`, `resolve`, `peer_fingerprint`, `ciphers`, `capture_peer_cert_chain`, `on_progress`.
- `verify_host => false` on its own throws, because the transport cannot skip only the host name check and would silently turn off chain verification. Use `verify_peer => false` to turn verification off. `cafile` together with `capath` throws too; pass one.
- Redirects are followed by the adapter. On a cross-origin hop only `Accept`, `Accept-Language`, `Accept-Encoding`, `User-Agent` and (when the body is kept) `Content-Type` are forwarded, so no credential header reaches the new host. No `Referer` is added.
- Proxy settings the transport cannot use (for example an `https://` proxy) surface lazily as a `TransportException` when the response is read, as with Symfony's own clients. The proxy is decided again for each redirect hop.
- The body is always buffered in memory.

#### Cross-origin Referer

With `allow_redirects.referer` enabled, Guzzle 7 sends the full previous URL (path and query) to the new origin; only Guzzle 8 trims it. `FledgeHandler` now reduces the Referer on every redirect hop itself: origin only across origins, no userinfo or fragment. A Referer you set on the first request is left alone.

#### Rolling back the integrations

1. Set the `FLEDGE_HTTP_*` flag of the affected integration to `false` (or `'client' => ['fledge' => false]` for one mailer), run `php artisan config:cache`, and restart workers (Octane, queue, Torque) so long-lived processes pick it up.
2. To drop the global Guzzle handler as well, call `Factory::globalHandler(null)`.
3. Native database driver: change `driver` back to `fledge-mariadb`/`fledge-mysql`, or switch single fiberio hooks off with `FLEDGE_FIBERIO_HOOKS` (for example `FLEDGE_FIBERIO_HOOKS=none`), then `config:cache` and restart workers.

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
