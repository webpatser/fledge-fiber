# Fledge Fiber

Non-blocking async drivers for the [Fledge framework](https://github.com/webpatser/fledge). PHP 8.6 only.

Provides fiber-based database (MySQL, MariaDB, PostgreSQL), Redis, HTTP, WebSocket, filesystem, and parallel processing drivers that integrate seamlessly with Laravel's service container.

## Requirements

- PHP 8.6+
- [revolt/event-loop](https://github.com/revoltphp/event-loop) ^1.0
- fledge-framework v13.42.0.1 and later require fledge-fiber 13.42.0.1 or later (earlier v13 frameworks work with any fiber v13 release)

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

Honored beyond the basics: Postgres `sslcert`/`sslkey`/`sslrootcert` and `keepalives*` (libpq connection string), Postgres session settings (`isolation_level`, `timezone`, `search_path`/`schema`, `synchronous_commit`, `charset`) via libpq startup `-c` options so `DISCARD ALL` on pool checkout restores instead of wipes them, MySQL/MariaDB TLS via `Pdo\Mysql::ATTR_SSL_CA`/`ATTR_SSL_CAPATH`/`ATTR_SSL_CERT`/`ATTR_SSL_KEY`/`ATTR_SSL_CIPHER` in `options` (any of these present in `options` requests TLS, whatever its value; `ATTR_SSL_VERIFY_SERVER_CERT` alone never requests TLS and only modifies verification; TLS is skipped for unix sockets), `ATTR_INIT_COMMAND` (runs on every new pooled connection), and per-connection `isolation_level`/`timezone` on MySQL through `SessionInitializingConnector`.

Explicitly unsupported PDO attributes: `ATTR_CASE` (server column names always), `ATTR_ERRMODE` (the shim always throws, Laravel's default), `ATTR_ORACLE_NULLS`, `ATTR_STRINGIFY_FETCHES` (native types from the wire), `ATTR_EMULATE_PREPARES` (statements are always server-prepared), `ATTR_PERSISTENT` (pooling replaces it).

MySQL 5.7: there is no live server-version probe, so set `'version' => '5.7'` in the connection config to get the `NO_AUTO_CREATE_USER` strict mode string.

Transactions on `fledge-mysql`, `fledge-mariadb` and `fledge-pgsql` are pinned per Laravel connection, not per fiber. While one fiber (or the main context) has a transaction open, a query, prepared-statement execute, begin, commit or rollback from any other fiber on that connection throws a `LogicException` instead of silently running inside it. Give concurrent fibers separate connections, or use the native drivers below, which keep transactions per fiber.

### Native MySQL/MariaDB drivers (`fledge-mysql-native`, `fledge-mariadb-native`)

A pool of real `pdo_mysql` connections running on [php-fiberio](https://github.com/webpatser/php-fiberio), one lease per fiber. Fiberio makes the stock PDO socket I/O yield to the Revolt event loop, so you get Laravel's own connectors, TLS, session settings and error shapes without the userland wire protocol. A fiber takes a connection for the duration of a query (or a whole transaction, or a `cursor()` until its generator ends) and gives it back afterwards. A fiber that finds the pool empty waits for a free lease, and fails with a `RuntimeException` after `pool_wait_timeout`.

#### When to use which driver

| | `fledge-mariadb` / `fledge-mysql` | `fledge-mariadb-native` / `fledge-mysql-native` |
|---|---|---|
| Wire protocol | Implemented in PHP | Stock `pdo_mysql` (C) |
| Extra requirements | None | `php-fiberio` 0.3.0+, NTS PHP only |
| Transactions | Pinned per connection; other fibers get a `LogicException` | Per fiber, each on its own lease |
| CPU per row, bulk read (10,000 rows) | 11.11 us | 0.63 us |
| CPU per row, bulk read (20,000 rows) | 6.55 us | 0.19 us |
| Wall time, 32 fibers x `SELECT SLEEP(0.05)` | 0.057 s | 0.058 s |

Both overlap concurrent I/O equally well; the native drivers cost far less CPU per row on large reads. Numbers are from `bench/database/results/local-mariadb11-2026-10-08.md` (MariaDB 11.8, PHP 8.5.11, local TCP, scale 0.2, 5 runs). On single-row point lookups the native driver spent 60.76 us of CPU per row against 161.26 us for `fledge-mariadb`, but more than blocking stock `pdo_mysql` through Laravel (25.60 us), as it adds pool and lease handling.

#### Install fiberio

```bash
pie install webpatser/php-fiberio
```

- NTS builds of PHP only; the extension is v0.x (0.3.0 is the latest; the C extension is unchanged since 0.2.x). Source and releases: <https://github.com/webpatser/php-fiberio>.
- php-fiberio 0.3.0 or later is required.
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

With php-fiberio 0.3.0 or later the extension can also make `sleep()` and friends, DNS lookups and `ssl://`/`tls://` client streams fiber-aware. The native connector enables them for the whole process through config `fledge-http.fiberio_hooks`, fed by the env var `FLEDGE_FIBERIO_HOOKS`:

```env
FLEDGE_FIBERIO_HOOKS=sleep,dns,ssl   # default: all
```

- Values: a comma list (or array) of `sleep`, `dns`, `ssl`, or `all`. `none`, `off`, `no`, `false`, `0` and the empty string turn every optional hook off (plain `tcp://` and `unix://` stay hooked, which is what the driver needs).
- Unknown names are ignored with one logged warning per process. If no valid name is left, the result is no hooks, never all.
- Precedence: the connection's own `fiberio_hooks` key, then `fledge-http.fiberio_hooks` (env `FLEDGE_FIBERIO_HOOKS`), then `all`.
- fiberio state is process-global and `enable()` runs once, so the first native connection to connect decides the hooks for the whole process. Set it app-wide through the env var rather than per connection.
- Needs php-fiberio 0.3.0.
- The setting is read through config, so it survives `php artisan config:cache`. Run `config:cache` again after changing the env var.

#### Caveats and migration notes

**Requirements**
- **TLS needs one of the TLS options.** The native drivers use stock `pdo_mysql`, which requests TLS when any of `ATTR_SSL_CA`, `ATTR_SSL_CAPATH`, `ATTR_SSL_CERT`, `ATTR_SSL_KEY` or `ATTR_SSL_CIPHER` is present in `options`, whatever its value. `ATTR_SSL_VERIFY_SERVER_CERT => false` on its own connects in plain text. `fledge-mariadb` uses the same trigger as `fledge-mysql` (its connector extends the MySQL one), so both behave alike.
- **Use a socket or IP address, or enable the DNS hook.** Without the `dns` hook (`FLEDGE_FIBERIO_HOOKS` without `dns`), resolving a hostname blocks the whole process while connecting. With the `dns` hook, the lookup runs on a helper thread and only the calling fiber waits.
- **No benefit under FPM** without an event loop: requests run one at a time, so the pool just adds overhead.

**Behaviour**
- **Fiberio is process-wide.** Once enabled, every PHP stream client is affected, not only PDO. A stream shared by two fibers (Predis, SMTP transports, the Guzzle stream handler) now throws "stream is in use by another fiber" instead of blocking. Give each fiber its own client, or serialize access.
- **Child fibers and transactions.** A child fiber started inside a transaction runs on a separate lease, outside that transaction, so it cannot see the uncommitted rows (and with a small pool it waits until `pool_wait_timeout`). A child fiber does not share the parent's session either: `SET` values or foreign key checks turned off in the parent do not apply to it.
- **`afterCommit` callbacks** are routed per fiber. A fiber that is not inside its own transaction runs them immediately, even while the main context has a native transaction open (its writes already autocommitted on another connection). A pgsql or `fledge-mariadb` transaction nested inside a fiber's native transaction gets its callbacks deferred to the native commit, and they are not dropped if only the inner transaction rolls back (stock Laravel attaches them to the innermost transaction). Rare, cross-connection only.
- **Custom transactions manager.** Fledge wraps the `db.transactions` binding only when it is Laravel's own `DatabaseTransactionsManager`. An app's own subclass stays bound as is: native connections still keep their transactions and `DB::afterCommit()` callbacks per fiber, but callbacks registered on the container from a fiber (after-commit queued jobs, broadcasts) go to that subclass unwrapped.
- **Shared connection state.** `pretend()` and `recordsModified` live on the Laravel connection object and are shared by all fibers.
- **Bare `DB::getPdo()`** keeps its lease until the fiber's outermost scope ends, or until the callback ends (main context: `DB::disconnect()`) if its session changed, even if it is never used. Prefer the query builder.
- **Unbuffered queries:** fetches are not ownership-checked, so only the fiber that ran the query may fetch from it.

**Testing**
- **RefreshDatabase** wraps only the main context. Other fibers use other leases and do not see the test transaction. A child fiber that waits on rows locked by the test transaction can hit error 1205 (lock wait timeout). Queue and event after-commit hooks bypass per-fiber routing under RefreshDatabase.

**Operations**
- **Exhausted pool.** A fiber waiting on a full pool (for example `pool_size => 1` with a second fiber) fails with a `RuntimeException` after `pool_wait_timeout`; the message names leaked leases. A fiber whose connection is pinned and that awaits child fibers needing the same pool can exhaust it (for example `pool_size => 1` after `disableForeignKeyConstraints()`): the children fail after `pool_wait_timeout`.
- **Pool size is per pool.** `pool_size` applies to each pool (write, read and direct), so a read/write split can open 2 to 3 times that many connections.
- **Octane.** The `DisconnectFromDatabases` listener closes the pool, which fails open transactions of other in-flight requests when running a fiber server.
- **Crashes.** A fiberio crash is a C-level crash and takes the worker down. Keep it to workers you can restart (Octane, queue workers) and treat the v0.x version as such.
- **`quote()` doubles single quotes.** `pdo_mysql` writes `\'` for a single quote, Fledge writes `''`, because the statement may run on another pooled connection than the one `quote()` looked at. To honour `NO_BACKSLASH_ESCAPES` it may open a pooled connection to read the server's SQL mode. The result is safe on any connection unless every known connection reports `NO_BACKSLASH_ESCAPES` and the statement then runs on a connection without it, which only happens when the application changes `sql_mode` itself.
- **`secure_key_retrieval` exists only in Fledge and is off by default.** Like `pdo_mysql`, `fledge-mysql` and `fledge-mariadb` fetch the server's RSA key for `caching_sha2_password` full authentication and `sha256_password` over a connection without TLS, where a host on the path could substitute its own key. TLS, a pinned key (`Pdo\Mysql::ATTR_SERVER_PUBLIC_KEY`, a PEM file path) or this option avoids that; with the option on, such a connect fails instead of fetching the key:

  ```php
  'options' => [Pdo\Mysql::ATTR_SERVER_PUBLIC_KEY => '/path/to/server-public.pem'],
  // or
  'secure_key_retrieval' => true,
  ```
- **LOCAL INFILE follows mysqlnd.** `Pdo\Mysql::ATTR_LOCAL_INFILE` allows it; `ATTR_LOCAL_INFILE_DIRECTORY` alone allows only files whose real path lies inside that directory (a strict `realpath` check; with `ATTR_LOCAL_INFILE` on the directory is ignored but must still be openable). A refused request fails the query with error 2068 and leaves the connection usable.

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
- Elasticsearch hosts go through `ResurrectingNodePool`: a host that hit a network error is skipped for 5 seconds and then tried again. When every host is dead and none is past its window the request fails fast with `NoNodeAvailableException` (no HTTP attempt, so pdphilip's default 3 retries cannot stack up timeouts on a single host); after the window the host is tried again. Per connection (top level or under `options`): `resurrect_after` in seconds, or `node_pool` as a `NodePoolInterface` instance or class name (`false` for elastic-transport's `SimpleNodePool` with `NoResurrect`). A pool set with `setNodePool()` on the builder wins.

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

## Event loop driver

Long-running workers pick their Revolt driver with `FLEDGE_EVENT_LOOP`:

- `auto` (default): the poll driver on PHP 8.6 (`Io\Poll`: epoll on Linux, kqueue on macOS/BSD), Revolt's own choice where no backend exists.
- `poll`: always the poll driver; throws if no backend is available.
- `select`: Revolt's `stream_select` driver. This is the rollback value.
- `revolt`: whatever Revolt's `DriverFactory` creates (honours `REVOLT_DRIVER`).

Call `Fledge\Async\EventLoop\LoopDriver::install()` in the worker after it has forked, before the loop starts: kqueue and epoll descriptors do not survive `fork()`, and `install()` refuses to swap a running or non-empty loop.

The default was decided by a benchmark gate, see `bench/eventloop/README.md`.

## Differences from stock Laravel

Where the Fledge drivers deliberately or unavoidably behave differently from Laravel's stock drivers, as implemented.

### Database

- **MySQL/MariaDB TLS is requested when a TLS option is present.** `fledge-mysql` and `fledge-mariadb` request TLS when at least one of `Pdo\Mysql::ATTR_SSL_CA`, `ATTR_SSL_CAPATH`, `ATTR_SSL_CERT`, `ATTR_SSL_KEY` or `ATTR_SSL_CIPHER` is present in `options`, whatever its value (`null` and `''` included), like `pdo_mysql`, which then refuses a server without TLS.
- **No TLS offered means no connection.** When TLS is requested and the server greeting does not advertise SSL support, the connection fails with `SqlConnectionException` before any credentials are sent; it never falls back to plaintext.
- **`ATTR_SSL_VERIFY_SERVER_CERT` alone does not enable TLS.** It only modifies verification (`false` skips peer verification) once one of the options above has requested TLS.
- **One pooled connection per fiber scope on `fledge-mysql` and `fledge-mariadb`.** `pdo_mysql` is one connection per PDO, so `SET`, user variables, temporary tables, `GET_LOCK` and `LAST_INSERT_ID()` carry over from one statement to the next. Fledge gives each fiber (and the main context) one pooled connection on its first statement, and its transactions run on it. The differences from `pdo_mysql`:
  - Under pool contention a pin whose session is provably unchanged goes back to the pool when another caller waits and the owner is between statements, so its connection can change between statements. A session-reading function in a later statement (`FOUND_ROWS()`, `ROW_COUNT()`, `CONNECTION_ID()`, `SHOW WARNINGS`) may then read another session. Use `PDO::lastInsertId()`, which follows the fiber and not the connection. The statement straight after an `INSERT`, `UPDATE`, `DELETE` or `REPLACE` keeps the connection, as do open transactions, unread rows and a prepared statement that has not run yet.
  - A statement is judged from its text. Session changes made by triggers or stored functions (also through a view) are not seen: the connection is not marked changed and is not reset, so such a change can go to the next fiber with the connection. A query that calls a stored function or any name that is not built-in on both MySQL 8 and MariaDB 11 (for example `point()`, `ANY_VALUE`, `MEMBER OF`, `uuid_to_bin`, `regexp_like`, `json_query`), uses a user variable, `SELECT ... INTO`, a lock function, `SQL_CALC_FOUND_ROWS`, a temporary table, a backslash anywhere in the text, a `/*!` executable comment, `--` followed by a control character or a second statement counts as changing the session; `MATCH (columns) AGAINST (...)` (what `whereFullText` emits; `against(` anywhere else is dirty), plain comments, optimizer hints, sized casts, math, date, JSON reader and read-only spatial functions and CTE column lists do not. A regex engine failure while judging counts as changing it too.
  - A fiber that changed its session holds a pool slot until its scope ends. Waiting for a slot ends after `pool_wait_timeout` (default 30 seconds, see below), and a fiber with a changed session that awaits a child fiber needing a connection on a full pool waits until then; with `pool_wait_timeout => 0` it waits forever.
  - A changed session costs a reset on release: `COM_RESET_CONNECTION`, then one query that re-applies the session settings and `USE` of the configured database, two round trips. A clean pin goes back without a reset. A server without `COM_RESET_CONNECTION` (MySQL before 5.7.3, MariaDB before 10.2.4, some proxies) gets its connection closed instead, so the next fiber opens a new one; one line is logged per process.
  - Transactions run at the session's isolation level, as with `pdo_mysql` (the server default or the connection's `isolation_level`). Earlier versions ran every transaction at READ COMMITTED.
  - Results are buffered when executed, as `pdo_mysql` does by default. With `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false` rows stream, and a second statement while rows are unread fails with error 2014, as in `pdo_mysql`.
- **`pool_wait_timeout` defaults to 30 seconds on all three pools.** On `fledge-mysql`, `fledge-mariadb` and `fledge-pgsql` it is the seconds a caller may wait for a connection (the wait on a full pool and the connect included, on Postgres also the `DISCARD ALL` reset); unset means 30, and 0, '0' or '' means no limit. A timeout throws `SqlException` with code 2002, which Laravel reports as a `QueryException`; it is deliberately not treated as a lost connection, so Laravel does not reconnect into a second pool. A pool class built directly through the async API keeps no limit unless `waitTimeout` is passed. On `fledge-mysql-native` and `fledge-mariadb-native` it is the seconds a fiber waits for a free lease (default 30; a value of 0 or below throws an `InvalidArgumentException` when the pool is built; the timeout throws a `RuntimeException`).
- **MySQL and Redis connects fail at once.** A refused connect makes one attempt and throws, as `pdo_mysql` and phpredis do; there is no socket-level retry (it used to add about 6 s). The connectors no longer use a globally set `Stream\socketConnector()`; the async API accepts a connector (for example `new SocketMysqlConnector(new RetrySocketConnector(new DnsSocketConnector()))`) if you want retries.
- **The native PDO pool pins a lease whose session may have changed, then discards it.** `pdo_mysql` has no reset command (no `COM_RESET_CONNECTION`), so on `fledge-mysql-native` and `fledge-mariadb-native` a lease whose session may have changed stays pinned to its fiber for the rest of the fiber's scope (the whole request or job callback): its following statements run on the same connection and see their own session state, as with one stock PDO. When the scope ends the connection is discarded instead of returned and the pool opens a fresh one for a later lessee; session state never reaches another fiber. Dirty is decided per statement in `NativePdo::prepare()`, `exec()` and `query()` with the MySQL classifier above (plus clean read-backs of `CONNECTION_ID()`, `DATABASE()`, `FOUND_ROWS()`, `ROW_COUNT()` and savepoint statements), and `setAttribute()` is dirty. `quote()`, `getAttribute()`, `lastInsertId()` and `inTransaction()` are clean, so Telescope or Debugbar do not cause reconnects. `setAttribute()` and `getAttribute(ATTR_SERVER_INFO)` through a reference that no longer owns the lease throw `LeaseOwnershipException`. The differences from stock `pdo_mysql`:
  - Session state (`SET @x`, `Schema::disableForeignKeyConstraints()`, temporary tables, `GET_LOCK`) lasts for the fiber's scope, not for the worker process: With a fiber per request or job (Fledge HTTP server, Torque) the next one starts with a fresh session. In the main context (artisan commands, `queue:work`, Horizon, Octane on Swoole, RoadRunner or FrankenPHP, PHPUnit) session state carries from job to job until `DB::disconnect()`, `purge()` or `DB::reconnect()`, as with stock Laravel's single PDO; a lost-connection retry drops only the slot that failed. Write, read and direct slots pin on their own, so with a read/write split a `SET` on the write connection is not seen by a read, as with stock's two PDOs.
  - A pinned connection counts against `pool_size` until the fiber's scope ends. The pinning fiber's own statements reuse its connection; other fibers wait for a slot up to `pool_wait_timeout`. A fiber that changes its session costs one extra connection for its whole scope, not one per statement; a clean lease goes back without a round trip.
  - Read-back values of a clean lessee (`LAST_INSERT_ID()`, `ROW_COUNT()`, `FOUND_ROWS()`, `SHOW WARNINGS`) stay readable by the next lessee.
  - A raw `DB::getPdo()` that is never used holds its connection until the fiber's outermost scope ends, or until the callback ends (main context: `DB::disconnect()`) if its session changed, and a raw PDO held after a discard stays open outside the pool count.
  - Triggers, views and stored code are invisible to the text check, so a change they make can still reach the next lessee.
  - Residual risks:
    - When another context replaces the pool mid-request (for example the Octane `DisconnectFromDatabases` listener under a fiber server), a fiber with a pinned connection continues on a fresh session without an error. Do not rely on session-level guards such as `SET SESSION TRANSACTION READ ONLY`, `SET ROLE`, `sql_safe_updates` or a tenant variable surviving that.
    - A statement classified as session-changing holds its connection for the rest of the request or job, including slow external awaits, so size `pool_size` for the number of fibers that change their session at once.
    - Long-lived main-context workers keep one pinned connection, and any held `GET_LOCK`, until disconnect.
- **An abandoned transaction is rolled back by the server.** When a callback ends with a transaction open on the native pool, its lease is discarded (the connection is dropped, so the server rolls the transaction back) and the transaction's after-commit callbacks are dropped. On the amphp MySQL driver a pin with an open transaction is closed when its scope ends. Stock PDO would keep the transaction open until the request ends.
- **MySQL text-protocol results return BIT as raw bytes.** Unprepared statements (the text protocol) give a BIT column as the raw byte string; prepared statements give an integer. An unsigned BIGINT of 2^63 and above is a decimal string in prepared results (as mysqlnd returns it).
- **Postgres resets a pooled connection only when its session may have changed.** The pool runs `DISCARD ALL` on checkout of a new connection or one that ran a statement the classifier cannot prove clean (`SET`, `LISTEN`, `PREPARE`, temporary tables, advisory locks, user functions, an abandoned or failed transaction); a clean connection is reused as it is. `INSERT`, `UPDATE`, `DELETE` and `MERGE` count as dirty because of triggers, so a transaction with DML costs a `DISCARD ALL` on the next checkout. A failed `notify()` leaves its connection to be reset (a lost connection is closed). Function names with non-ASCII characters are never treated as built-ins. A function or rule behind a view or a trigger is invisible to the text check.
- **A fiber with a pinned native session fails once when its pool is replaced.** When another fiber or context disconnects or reconnects the connection while a fiber holds a pin with changed session state (variables, locks, temporary tables), that fiber's next statement throws `LostConnectionException` once and then runs on a fresh session. Stock Laravel reconnects silently and drops the session state. Clean leases move to the new pool without a word. A dirty pin whose pool was closed by another context keeps its connection open until the pinned fiber's (or the main context's) next statement, `disconnect()`, or the end of the fiber.
- **In-process browser tests on the native driver.** Request fibers get their own connection and cannot see the test's uncommitted transaction, so `RefreshDatabase` and `DatabaseTransactions` do not work with the Pest browser plugin on `fledge-mysql-native` and `fledge-mariadb-native`. Use `DatabaseTruncation`, or run browser tests on the stock driver.
- **Postgres decodes user-defined types created later as strings.** The type table is read once per process for each host, port, user and database and then reused by every connection to that database. A type created after it was read (a new enum, composite or domain) is decoded with the default text type, so it comes back as a string until the worker restarts. Enum and composite values are strings, as with `pdo_pgsql`, and the cache is per database. On the `pgsql` path an array column whose type is in the type table is parsed into a PHP array, where the `pq` path returns the `'{a,b}'` literal.

### Redis

These reply shapes match phpredis; the differences listed after them do not:

- Inside `MULTI`, a queued command returns the connection (also through `setnx`, `hsetnx` and the scan wrappers), so `multi()->set()->incr()->exec()` chains.
- `exec()` returns one reply per queued command. Failed commands, nil replies and nested nils are `false`.
- A WATCH-aborted `EXEC` returns `false`.
- A command Redis rejects while queueing (wrong arity, unknown command) returns `false`, and the following `EXECABORT` makes `exec()` return `false`.
- In a pipeline, a failed command, a nil reply and nested nils (`MGET` elements) are `false`, and a `BLPOP` or `BRPOP` timeout is `[]`.
- Inside an open transaction `eval` is sent as `EVAL` (an `EVALSHA` would only be queued, so its `NOSCRIPT` fallback could not run). A nil result and nils inside result tables are `false`.
- Commands through `__call` (for example `fcall`) map errors nested in an array reply to `false`.
- Connection errors still throw.

Remaining differences:

- `executeRaw` keeps a nil reply and nested nils as `null`, and inside `MULTI` it still throws when Redis rejects the command while queueing.
- Direct `command()` calls outside a pipeline and a transaction keep nested nils as `null`, as Laravel's own `mget` wrapper returns them.
- A `BLPOP` or `BRPOP` timeout inside `EXEC` is `false`, not `[]`.
- Timeouts of other blocking commands in a pipeline (`BZPOPMIN`, `BZPOPMAX`) are `false`, where phpredis returns `[]`.
- A top-level error reply outside a transaction throws `QueryException`; phpredis returns `false` (for example `incr` on a non-integer, an `eval` that returns an error, `rawCommand` with an unknown command).
- Status replies are `'OK'` in Fledge and `true` in phpredis (`set`, `setex`, `hmset`, also in `exec()` and pipeline slots). `multi()->set()->get(missing)->exec()` gives `[true, false]` in phpredis and `['OK', false]` in Fledge.
- A top-level nil from a command without a Laravel wrapper (`hget`, `lpop`, `zscore` and others through `__call`) is `null` in Fledge and `false` in phpredis.
- `hmget` returns `null` for missing fields in Fledge and `false` on stock Laravel.
- `bzpopmin` outside a pipeline: a timeout is `null` in Fledge and `[]` in phpredis.
- A pipeline run inside an open `MULTI` returns the connection in each slot (phpredis raises a fatal error there).
- In a pipeline `HMGET` returns a list, where phpredis returns an array keyed by field.
- **Blocking commands run on their own connections.** `BLPOP`, `BRPOP`, `BRPOPLPUSH`, `BLMOVE`, `BLMPOP`, `BZPOPMIN`, `BZPOPMAX`, `BZMPOP` and `XREAD` / `XREADGROUP` with `BLOCK` get a dedicated connection, so other fibers are not blocked behind them (phpredis blocks only its own process). Up to `FledgeRedisConnection::BLOCKING_IDLE_LIMIT` (2) idle dedicated connections are kept; only idle ones are capped, so N concurrent blocking calls open N sockets. A pipeline that holds a blocking command runs in order. Inside `MULTI` the command stays on the transaction connection. `disconnect()` fails blocking calls in flight with the lost-connection exception and they are not retried (not on cluster links).
- **`xread()` and `xreadgroup()` follow phpredis**, including `[]` on a `BLOCK` timeout.
- **`SELECT` survives a reconnect.** The link selects the database again after a reconnect, also for a `SELECT` queued in `MULTI` (applied only when its own `EXEC` entry succeeded) and for `executeRaw(['SELECT', n])`. A link reconnects lazily on the next command after `QUIT`, a server-side close or a closed connection.
- **The subscriber reconnects by itself.** When the pub/sub connection drops, phpredis throws to the `subscribe()` caller; Fledge's subscriber reconnects and resubscribes every channel and pattern on its own, after a short backoff (the default strategy: 0.1 s doubling to 1 s, back to the start once a subscription is confirmed or a message arrives; the connection's `backoff_*` options are not passed to the subscriber). A refused reconnect after an established connection dropped (a Redis restart) is retried for up to about 6 seconds before the subscriptions fail; a refused first connect fails at once. Messages published while it is disconnected are lost, because plain pub/sub has no replay.
- **A rejected channel raises Fledge's `QueryException`, not `\RedisException`.** A server error to `SUBSCRIBE` or `PSUBSCRIBE` (for example `-NOPERM`) fails the `subscribe()` / `psubscribe()` call with `Fledge\Async\Redis\Protocol\QueryException` instead of reconnecting into the same rejection. Only the subscriptions of that channel fail.

### HTTP

- A request body of 1,000,000 bytes or more, or one with a `Transfer-Encoding` header and no known size, is streamed in 64 KiB reads instead of buffered; smaller bodies stay buffered. A streamed body cannot be replayed, so a user-supplied async client wrapped in `RetryRequests` fails its retry.
- On HTTP/1 a request whose write fails is never reused: the connection is closed. An early response, such as a 413 sent before the body was written, is still delivered in full before the close.
- `read_timeout` maps to the client's inactivity timeout (the wait between reads).
- `max_body_size` caps the response body; without it there is no cap, as with Guzzle.
- `connect_timeout` is capped at `timeout` when both are positive, as curl does. Without a `connect_timeout` (absent or 0) the connect and TLS handshake limit is 300 s, curl's own default, which a positive `timeout` still caps.
- A positive `timeout` bounds the whole request, as curl's total timeout does: the handler cancels the request at `timeout` seconds, counted from the start, so the wait for a free slot in a connection-limited pool, the connect, the TLS handshake and the transfer all count. Without a `timeout` (absent or 0) nothing bounds the total; connect and TLS handshake still get `connect_timeout` each (or the 300 s default).
- `stream => true` without `read_timeout` uses `timeout` as the idle bound. The transfer timeout stays armed while the body is read (the HTTP/1 body reader and the HTTP/2 per-stream watcher use it), so `timeout` also caps the total time including body reads.
- **A coalesced DNS lookup cannot be cancelled by its callers.** Callers asking for the same name and type share one query. It runs without any caller's cancellation and ends at the resolver's configured timeout (per attempt, over the configured attempts); a caller that is cancelled stops waiting, but the query keeps running for the others.
- **The HTTP/2 client limits queued control frames.** A server that floods PING, SETTINGS or pushed streams without reading the replies gets the connection closed (`ENHANCE_YOUR_CALM`) at 1000 queued control frames. The limit cannot be set from client configuration.
- **Redirects in the async HTTP client's own follower** (`FollowRedirects`; not used on Laravel's HTTP client path, where Guzzle follows redirects): the request body is dropped on every redirect, a non-GET request is not followed on 307 and 308, and a HEAD request becomes a GET.
- **The streaming form parser returns raw key names** (not on the Laravel request path). PHP rewrites spaces and dots in names and builds arrays from brackets; this parser only URL-decodes the key. Multipart parsing requires the body to start with the boundary (no preamble) and CRLF line endings (no bare LF).
- **Multipart part headers are capped at 16384 bytes by default** (`StreamingFormParser::DEFAULT_PART_HEADER_SIZE_LIMIT`); a larger header block is answered with 400. Pass `partHeaderSizeLimit` to the `StreamingFormParser` constructor to change it.

### WebSocket

- **The server caps what queues up behind a slow client.** Each client may have 16 MiB and 65,536 messages waiting by default; past either, the client is closed with 1008 and its pending sends fail with `WebsocketClosedException`. The caps are per client, there is no global cap. Pass `null` for `maxQueuedBytes` or `maxQueuedMessages` to `WebsocketClientGateway` to disable one. A single message larger than the byte cap is still delivered to a client with an empty queue.

### Livewire

- **Concurrent component updates are off by default** (`fledge-livewire.concurrent` is `false`). Blade and Livewire rendering rely on global state (view data stacks, component and slot stacks, shared sections) that interleaving fibers can corrupt.
- To opt in, publish the config (`php artisan vendor:publish --tag=fledge-livewire-config`) and set `concurrent` to `true`, only when every component updated in one request is known to be safe to render interleaved. `max_concurrent` (default 10) limits how many components update at once.

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

## PHP 8.6 Features

This library requires PHP 8.6 and uses:

- `#[\NoDiscard]` on Future-returning methods
- `clone()` with property overrides for immutable configs
- `readonly class` for value objects (76 classes)
- Typed class constants
- First-class callable syntax throughout

## Versioning

Follows Fledge versioning: `v13.x.y.z` where the first three digits match the Laravel version and the fourth is the fledge-fiber patch level.

## License

Apache-2.0. Portions are derived from [amphp](https://github.com/amphp) (MIT); see `LICENSE` for the original notice.
