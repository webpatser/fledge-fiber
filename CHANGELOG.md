# Changelog

## v13.4.0.1 — 2026-04-13

### Bug Fixes
- **Database**: Fix `lastInsertId` not propagated for prepared statements — `FledgePdoStatement::execute()` now calls `trackLastInsertId()` on the parent PDO shim, so Eloquent models with auto-increment IDs receive the correct ID after `save()`.
- **Async**: Fix 28 `#[\NoDiscard]` violations on `Future::finally()` — all fire-and-forget `onClose`/`onCommit`/`onRollback` subscriptions now call `->ignore()` to suppress PHP 8.5 warnings.

### Refactor
- Fix 27 PSR-4 namespace mismatches across Database, WebSocket, Http, Parallel, and Internal modules.
- Rename base PDO class to `FledgePdo` to match filename and Fledge naming convention.
- Remove all remaining `Amp`/`amphp` references from source code — renamed aliases, error messages, user-agent strings, cache prefixes, temp file paths, FFI scope, HAR attributes, and process titles.
- Rename `amp-hpack.h` to `fledge-hpack.h`.
- Rename test files from `Amphp*` to `Fledge*` prefix and fix class references.

### Removed
- Delete 251 dead test files in `tests/Amp/` — used old `Amp\*` namespaces, never tested fledge-fiber code.

## v13.3.0.1 — 2026-04-10

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
