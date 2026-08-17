# Changelog

## Unreleased

### Redis
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
