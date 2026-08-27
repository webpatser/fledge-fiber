# Changelog

## v13.29.0.2 - 2026-08-27

Full config-parity release for the Laravel integration layer. A three-way audit against Laravel's stock drivers (PDO connectors, phpredis connector, Guzzle cURL handler) found options that fledge-fiber silently ignored; this release makes them work or fail loudly. Read the behavior changes below before upgrading.

### Database

- Postgres: `sslcert`, `sslkey`, and `sslrootcert` config keys are now passed through to libpq, matching the pgsql driver.
- Postgres: `isolation_level`, `timezone`, `search_path`/`schema`, `synchronous_commit`, and `charset` now ride the libpq startup packet as `-c` options, so `DISCARD ALL` on pool checkout restores them instead of wiping them. Previously they were applied to a single pooled connection and silently lost after the first checkout.
- Postgres: an omitted `host` now defers to libpq defaults (unix socket or localhost) instead of forcing 127.0.0.1.
- MySQL/MariaDB **behavior change**: `ATTR_SSL_*` options in `options` now actually enable TLS on the async driver; they were previously ignored and connections stayed plaintext. Configs that implied TLS may now surface certificate errors at deploy; that is the fix working. `ATTR_SSL_VERIFY_SERVER_CERT => false` disables peer verification.
- MySQL/MariaDB: `charset`/`collation` are only sent when configured (no more invalid `SET NAMES 'latin1' COLLATE 'utf8mb4_0900_ai_ci'`), the handshake charset now works on MariaDB and MySQL 5.7 (collation id 45 instead of the MySQL-8-only 255), and `ATTR_INIT_COMMAND` plus `isolation_level`/`timezone` run on every pooled connection via the new `SessionInitializingConnector`, not just the first.
- MariaDB: new `FledgeMariaDbConnector` whose strict mode never emits `NO_AUTO_CREATE_USER`; the `fledge-mariadb` driver now binds to it. MySQL strict mode adds `NO_AUTO_CREATE_USER` when the `version` config key is set below 8.0.11.

### Redis

- **Prefix migration note (breaking)**: the `prefix` option is now actually applied with phpredis OPT_PREFIX semantics. Keys written by earlier fledge-fiber versions (which ignored the prefix) become invisible to a prefixed connection after upgrading. Migrate with `redis-cli --scan` plus `RENAME` to the prefixed names, or clear the cache if the data is disposable. This restores physical-keyspace compatibility with phpredis deployments, verified by a differential test against phpredis itself.
- **In-flight command behavior change**: when a connection drops with commands awaiting responses, non-idempotent commands (`INCR`, `LPUSH`, `SET` with expiration, ...) are no longer silently resent after reconnect; they fail with `RedisInFlightCommandException` because they may already have executed server-side. Idempotent commands (the upstream RETRYABLE_COMMANDS whitelist) are still resent transparently.
- TLS context options, ACL usernames, `timeout`, `name` (CLIENT SETNAME), and `tcp_keepalive` now reach the connection instead of being dropped by URI round-tripping; `REDIS_SCHEME=tls` and `rediss://` URLs actually encrypt now, and unix sockets work with Laravel-style configs that carry a `host` key.
- `read_timeout` is honored: a blocked response throws `RedisTimeoutException` and drops the connection, like phpredis. `command_retries`, `max_retries`, `retry_interval`, and `backoff_*` are honored; the command retry loop mirrors upstream `PhpRedisConnection::command()`.
- Cluster seeds inherit TLS, auth, timeouts, and retry policy. Unsupported options (serializer, compression, pack_ignore_numbers, sentinel, distribute failover, predis client-side sharding) now throw `UnsupportedRedisOptionException` at connect time instead of being silently ignored.

### HTTP client

- Transport failures now reject as Guzzle exception types (`ConnectException` for socket/TLS/DNS/timeout failures, `RequestException` otherwise), restoring `Illuminate\Http\Client\ConnectionException` and the `ConnectionFailed` event, which the raw async exceptions silently bypassed.
- **Behavior change**: the hidden transport-level `RetryRequests(2)` interceptor is gone; `Http::retry()` is the only retry layer. Socket connects are single-attempt instead of three with backoff, so connection refusals fail immediately.
- **Behavior change**: redirect ownership moved from the transport to Guzzle's RedirectMiddleware. `allow_redirects => false` is enforced (it was a no-op), `max` follows Guzzle's default of 5, strict 307/308 method replay works, and no Referer is sent unless enabled (the transport previously leaked the full URL cross-origin).
- `verify => false`, CA overrides, client certificates, `crypto_method`, and proxies (`http://` via CONNECT, `socks5://`, array form with `no` exclusions) now take effect per request instead of being ignored, via a client cache keyed on the TLS/proxy option tuple and a new `HttpConnectSocketConnector`.
- `sink` streams to its target instead of silently buffering everything in memory, `stream => true` returns a lazily-readable body, `on_headers` is invoked, `delay` waits non-blockingly, `version => 2` negotiates h2 with HTTP/1.1 fallback, and `on_stats` reports real per-request timings (previously near-zero under `Http::pool()`) with connect/TLS durations and peer address.
- An 84-test loopback parity suite now exercises the handler directly (it remains disabled under PHPUnit in application test suites).

## v13.29.0.1 - 2026-08-27

### Database
- **Postgres keepalive options (parity with Laravel v13.29.0)**: the async Postgres driver now honors the `keepalives`, `keepalives_idle`, `keepalives_interval`, and `keepalives_count` database config keys, matching the upstream PDO `PostgresConnector`. `FledgePostgresConnector` forwards them into `PostgresConfig`, which emits them as libpq connection-string options (the driver connects through pecl-pq or ext-pgsql, so libpq applies the TCP keepalive behavior itself). Unset keys are omitted; `keepalives => 0` is passed through to disable keepalives explicitly. `PostgresConfig` gains matching getters, `with*`/`without*` withers, and `fromString()` parsing support.

## v13.26.1.1 - 2026-08-22

### Async
- **Upstream sync (amphp/amp v3.1.2 + v3.1.3)**: `Interval` now forbids cloning and serialization, preventing cancellation of the original event-loop callback through a clone or execution of arbitrary callbacks on deserialization. `Future::iterate()` holds the internal iterator through a `WeakReference`, so abandoning the returned iterable stops consumption of the source; previously iteration continued even after the iterable was destroyed. `CompositeCancellation::isRequested()` / `throwIfRequested()` now also check the wrapped cancellations directly, because the callback setting the internal exception runs asynchronously and could report a cancelled composite as not-yet-requested for a tick.
- **Upstream sync (amphp/pipeline v1.2.4 through v1.2.7)**: fixed early disposal of iterators created with `Pipeline::generate()`; fixed the inverted `isComplete()` return value on iterators from `Pipeline::concat()`; fixed pipeline termination with concurrency greater than one when the source throws while another coroutine already completed the pipeline; fixed the call ordering of the initial pipeline operator over an async source; fixed the position reported by a `Queue` concurrent iterator when a waiting consumer in the middle of the waiting list is cancelled; fixed consuming a value from a `Pipeline::generate()` iterator when consumption of the prior value was cancelled in the same event-loop tick; disposal of a queue iterator now relieves producer back-pressure; improved garbage-collection speed of early-terminated concurrent pipelines. `#[\Override]` attributes added throughout the concurrent iterator internals.

### HTTP
- **Bug fix**: `ConnectionLimitingPool::__destruct()` no longer closes connections that still have in-flight streams. Dropping the last reference to an `HttpClient` while a response body was still streaming aborted that body with "Socket closed" (and could stall a pending request forever, which is what has been hanging CI since the HTTP/2 loopback suite landed). Idle connections are still closed immediately; busy connections are closed once their streams finish, via an unreferenced poll timer.
- **Bug fix (from upstream http-server v3.4.6)**: `Driver\ClientFactory` imported the HTTP client's `SocketException` instead of `Fledge\Async\Stream\SocketException`, the exception `SocketClientFactory` actually throws.
- **Bug fix (from upstream hpack v3.2.2)**: the native HPack Huffman code table masks `chr()` input to one byte, avoiding a PHP 8.5 deprecation warning during header encoding.
- The HTTP/2 client ping counter and the test session id generator use `str_increment()` instead of the deprecated string increment operator (PHP 8.5).

### Parallel
- **Hardening (from upstream parallel v2.3.4)**: `ProcessContext` throws a `ContextException` when the runner script cannot be read, and `ThreadContext` when the parent PID cannot be determined, instead of continuing with a corrupt state.

### Dependencies
- `revolt/event-loop` updated to v1.0.9 (fixes a fiber-destruction-order segmentation fault) and `guzzlehttp/guzzle`, `guzzlehttp/psr7`, and the Symfony HTTP packages moved past all open security advisories (`composer audit` is clean).

## v13.26.1.0 / v13.25.0.2 - 2026-08-17

### Redis
- **Hardening**: The resp3 extension parser is only selected when the loaded extension is at least `0.1.4` (`Resp3ExtensionParser::MINIMUM_VERSION`). Versions before that corrupt the parser state machine on RESP2 nulls nested inside aggregates (XPENDING summaries, MGET with missing keys), so an app on an outdated extension was silently unstable regardless of its fledge-fiber version. An outdated extension now falls back to the pure-PHP `RespParser` (correct, just slower) and emits a once-per-process `E_USER_WARNING` pointing at `pie install webpatser/php-resp3`. New `Resp3ExtensionParser::isUsable()` / `versionIsSupported()` back the gate.
- **Bug fix**: A wire parse failure no longer turns `ReconnectingRedisLink` into a reconnect storm. The loop caught every `RedisException`, reconnected immediately, and resent the same queued commands; a deterministic receive failure (such as a parse error) therefore looped at thousands of connections per second until the local ephemeral port range was exhausted (~16k TIME_WAIT sockets to the Redis host within seconds, observed via the php-resp3 nested-null bug fixed in resp3 0.1.4). Parse failures now throw the new `RedisWireException` (subclass of `RedisException`), which fails the pending commands instead of resending them onto a stream that would corrupt the same way, and all reconnect attempts back off exponentially (0.1s doubling, capped at 1s), resetting once responses flow again.
- **Bug fix**: `RedisConfig` now percent-decodes the URI user-information part. Callers correctly `rawurlencode()` credentials when building a URI, but the parser took the encoded bytes verbatim, so any password containing a reserved character (`+`, `/`, `=`, `@`, `:`, common in generated and base64 secrets) was sent to `AUTH` still encoded and authentication failed. Splitting still happens before decoding, so an encoded colon inside either component cannot act as the separator.
- **Bug fix**: `RedisConfig` no longer discards the ACL username. The destructuring in `applyUri()` dropped it into an empty slot, and `Authenticator` only ever emitted the single-argument `AUTH`, so ACL users were silently unusable. The username is now parsed (with a `username` / `user` query-string fallback), exposed via `getUsername()`, and `Authenticator` emits the two-argument `AUTH <user> <pass>` when one is set.
- **Feature**: The `rediss://` scheme is accepted and selects a TLS connection. It previously threw `RedisException` from the scheme whitelist, which made every managed Redis provider that publishes a `rediss://` URL unreachable. `createRedisConnector()` builds a `ClientTlsContext` using the URI host as the peer name; `SocketRedisConnector` already performed the TLS handshake when a context was present. `RedisConfig` also gained `getHost()`, `usesTls()`, `withUsername()` and `withTls()`.

## v13.20.0.1 / v13.19.0.2 - 2026-07-15

### Async
- **Bug fix**: `FutureState` imported `UnhandledFutureError` from a namespace that does not exist (`Fledge\Async\Future\...` instead of `Fledge\Async\Internal\...`), so the FIRST unhandled future error in a process crashed the event-loop callback with `Class "Fledge\Async\Future\UnhandledFutureError" not found` instead of reporting the actual failure. Seen live masking an APNs HTTP/2 send error.

### Redis
- **Bug fix**: A wire parse failure in the Redis read fiber now errors the connection's response queue as a `RedisException` (carrying the underlying exception and a hex head of the offending chunk) instead of escaping the `EventLoop::queue` callback. The resp3 extension parser throws `\Resp3\RedisException` on malformed framing, which is not a Fledge `RedisException`; it escaped the catch, Revolt rethrew it as `UncaughtThrowable`, every pending future on the connection was stranded with no error, and the socket stayed open. Non-parser socket failures escaping the read loop are wrapped the same way. Seen live as `Uncaught Resp3\RedisException ... RESP3 parse error: expected LF after CR in length`; the hex chunk head in the new message exists to identify the actual bytes on the wire when the underlying framing corruption recurs.

## v13.19.0.1 - 2026-07-07

### HTTP
- **Server**: The HTTP QUERY method is now a known method and part of `AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS`. Laravel v13.19.0 added QUERY support across the framework (`Http::query()`, `PendingRequest::query()`, test helpers), and without this the async server would reject inbound QUERY requests with 405 before they reached the router, diverging from PHP-FPM behavior. The client side needed no change: `FledgeHandler` passes request methods through verbatim, and QUERY correctly falls outside the bodyless-method lists in `RequestNormalizer` and the server `Request` body handling.

## v13.11.1.1 - 2026-05-20

### Bug Fixes
- **Parallel**: The process context runner now catches `ChannelException` when sending its result, so a parent that closes the channel during shutdown no longer crashes the child with a spurious "could not send result" error. The runner also reports fatal startup failures via `php://stderr` and `exit(255)` instead of `trigger_error(E_USER_ERROR)`, which did not reliably terminate the child.
- **Parallel**: Fixed process contexts being unspawnable. The runner autoload paths assumed an installed-as-dependency directory layout, and an unresolvable stdin call was replaced with a readable resource stream over `STDIN`.
- **Parallel**: `flattenArgument()` renders `NAN` explicitly instead of casting it to string, avoiding a PHP 8.5 warning when building context error messages.
- **HTTP**: Fixed an idle HTTP/1 connection garbage-collection leak. `ConnectionLimitingPool` no longer leaves a stale `DeferredFuture` in its waiting map when a connection attempt fails, and `Http1Connection` holds a `WeakReference` to itself inside its timeout and idle-read closures so idle connections become collectible in long-running workers.

### Async
- `Pipeline` configuration methods (`buffer`, `concurrent`, `sequential`, `ordered`, `unordered`) now declare a `static` return type.

### Tests
- Added a MariaDB service to CI and `docker-compose`, plus an integration test that round-trips a value through a native `UUID` column.

## v13.4.0.1 - 2026-04-13

### Bug Fixes
- **Database**: Fix `lastInsertId` not propagated for prepared statements. `FledgePdoStatement::execute()` now calls `trackLastInsertId()` on the parent PDO shim, so Eloquent models with auto-increment IDs receive the correct ID after `save()`.
- **Async**: Fix 28 `#[\NoDiscard]` violations on `Future::finally()`. All fire-and-forget `onClose`/`onCommit`/`onRollback` subscriptions now call `->ignore()` to suppress PHP 8.5 warnings.

### Refactor
- Fix 27 PSR-4 namespace mismatches across Database, WebSocket, Http, Parallel, and Internal modules.
- Rename base PDO class to `FledgePdo` to match filename and Fledge naming convention.
- Remove all remaining `Amp`/`amphp` references from source code: renamed aliases, error messages, user-agent strings, cache prefixes, temp file paths, FFI scope, HAR attributes, and process titles.
- Rename `amp-hpack.h` to `fledge-hpack.h`.
- Rename test files from `Amphp*` to `Fledge*` prefix and fix class references.

### Removed
- Delete 251 dead test files in `tests/Amp/` (used old `Amp\*` namespaces, never tested fledge-fiber code).

## v13.3.0.1 - 2026-04-10

Initial release of Fledge Fiber as a standalone async library for the Fledge framework.

### Core
- `Fledge\Async` namespace with Future, async/await, cancellation, and pipeline primitives
- `#[\NoDiscard]` on all Future-returning public methods
- `clone()` with property overrides on all immutable config/option objects (PHP 8.5)
- 76 `readonly class` declarations
- 70+ typed class constants

### Drivers
- **Database**: MySQL/MariaDB binary protocol, PostgreSQL wire protocol, connection pooling
- **Redis**: RESP protocol client, pub/sub, TLS support, distributed locking
- **HTTP**: HTTP/1.1 + HTTP/2 client and server with form parser, router, sessions, static content
- **WebSocket**: Client and server
- **File**: Non-blocking filesystem operations
- **Parallel**: Multi-process worker pools

### Laravel Integration (`Fledge\Fiber`)
- Unified `FiberServiceProvider` auto-discovers all drivers
- Database connectors: `fledge-mysql`, `fledge-mariadb`, `fledge-pgsql`
- Redis connector: `fledge`
- HTTP client handler (replaces Guzzle's CurlHandler)
- Livewire concurrent component updates

### Bug Fixes (from upstream community PRs)
- HTTP/2 ping flood protection on active streams
- MySQL VarString encoding for binary protocol
- MySQL BIT column decoded as int
- HTTP client connections closed on pool destruct
- Byte-stream split() duplicate key fix
- Redis safe unsubscribe (no DisposedException)
- Redis TLS connection support
- `disperse()` function for concurrent closure execution

### Compat
- Removed all PHP version guards (requires 8.5+)
- Removed deprecated `stream_context_set_option()` fallback
