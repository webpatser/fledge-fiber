# Changelog

## Unreleased

### Fixed
- **Cross-origin redirects send only the origin as Referer, on any Guzzle version**: with `allow_redirects.referer` enabled, Guzzle 7's RedirectMiddleware sends the full previous URL (path and query included) to the new origin; only Guzzle 8 trims it. `FledgeHandler` now reduces the Referer on every redirect hop itself (strict-origin-when-cross-origin: no userinfo or fragment, origin only across origins), and the transport's own `FollowRedirects` interceptor does the same. A Referer set by the caller on the first request is left alone. The Symfony adapter adds no Referer, now covered by a test.

## v13.35.0.3 - 2026-10-08

### Added
- **`fledge-mysql-native` and `fledge-mariadb-native` drivers**: a pool of real `pdo_mysql` connections on [php-fiberio](https://github.com/webpatser/php-fiberio) 0.1.0 or later, built on Laravel's stock MySQL/MariaDB connectors. Install with `pie install webpatser/php-fiberio` (NTS builds only). See [Native MySQL/MariaDB drivers](README.md#native-mysqlmariadb-drivers-fledge-mysql-native-fledge-mariadb-native) for requirements and caveats.
- One connection lease per fiber, held for a query, a whole transaction, or a `cursor()` until its generator ends. Transactions, `lastInsertId` and `afterCommit` callbacks are routed per fiber.
- New connection config keys: `pool_size` (32), `pool_idle_timeout` (60 seconds), `pool_wait_timeout` (30 seconds). `'fiberio' => 'optional'` falls back to blocking PDO with one logged warning when the extension is missing. `PDO::ATTR_PERSISTENT` is rejected.
- Benchmark: a `native-driver` column in `bench/database` and a local MariaDB 11 run in `bench/database/results/local-mariadb11-2026-10-08.md`.

### Changed
- **Behaviour change: cross-fiber transaction guard** on `fledge-mysql`, `fledge-mariadb` and `fledge-pgsql`. The transaction pin is per connection, so a second fiber's queries used to run silently inside another fiber's open transaction and were committed or rolled back with it. `FledgePdo` now records the fiber that began the transaction and throws a `LogicException` when any other fiber (or the main context) prepares, executes, execs, begins, commits or rolls back on that connection while it is open. The owning fiber is unaffected. Use separate connections per concurrent fiber, or the native drivers.

### Notes
- `fledge-mysql` and `fledge-mariadb` keep their wire-protocol implementation and stay available. Switching to a native driver is a `driver` change in the connection config, and switching back is the rollback.

## v13.35.0.2 - 2026-10-07

### Database
- **No driver error leaks past the PDO shims any more**: v13.35.0.1 shaped only `SqlQueryError`. Connection failures, lost connections, pool and statement shutdown, cancellation, protocol errors and parameter errors still reached Laravel as `SqlConnectionException`, `SqlException`, plain `\Error` and friends, so `DetectsLostConnections` never reconnected and the connector retry never fired. `FledgePdoException::fromThrowable($e, $driver)` is now the single mapper, and every public method of `FledgePdo`, `FledgePdoStatement`, `FledgeMySqlPdo` and `FledgePostgresPdo` runs through one `guard()` that calls it. The connectors map anything raised while building the pool the same way. Real programming errors (`TypeError`, `ValueError`, and any `\Error` no driver condition explains) are rethrown untouched, and the original exception is always kept as previous.
- Connection failures now carry the exact text the C drivers produce and Laravel's `LostConnectionDetector` knows: MySQL `SQLSTATE[HY000] [1045] Access denied ...`, `[1049] Unknown database ...`, `[2002] Connection refused` / `Connection timed out` / `No such file or directory` / `php_network_getaddresses: getaddrinfo for ...`; Postgres `SQLSTATE[08006] [7] <libpq message>`. Like the PDO constructor, these use the native error number as an int `getCode()`. A connection lost after connecting becomes `SQLSTATE[HY000]: General error: 2006 MySQL server has gone away`, idle or mid-query, as mysqlnd reports it. On Postgres it becomes `SQLSTATE[HY000]: General error: 7 ...` with libpq's text: the server's FATAL (if it sent one) followed by `server closed the connection unexpectedly ...`, or `no connection to the server`. libpq's lost-connection results carry no SQLSTATE, so pdo_pgsql falls back to HY000; 08006 appears only in the connect-time `[7]` format.
- SQLSTATE descriptions now come from PDO's own table (`ext/pdo/pdo_sqlstate.c`), including `<<Unknown error>>` for unlisted states, instead of a per-class approximation. For example, `08S01` is now `Communication link failure` and `42P01` is `Undefined table`.
- **Transactions are as strict as PDO**: `commit()` or `rollBack()` with no active transaction throws `There is no active transaction`, and `beginTransaction()` inside one throws `There is already an active transaction`. Before, the first two returned false silently and the last one overwrote the pinned transaction, leaking its connection. A failed `COMMIT` or `ROLLBACK` now always releases the pin, because the driver has already deactivated the transaction (the server rolls back on a deadlock), so Laravel's retry can begin a fresh one.
- The MySQL driver now passes the server error number of a handshake ERR packet as the `SqlConnectionException` code, so connect errors (1045, 1049, 1044, 1040, 1129, 1130) take their number from the server instead of from the message text. Parsing the text remains the fallback.
- `FledgePdoStatement::bindValue()` handles `PDO::PARAM_LOB`, which Laravel's `Connection::bindValues()` uses for resources. As in pdo_mysql and pdo_pgsql, a stream resource is read from its current position to the end, and on Postgres the bytes go to the server as bytea (`PostgresByteA`) instead of text.
- **Laravel reconnects after a killed connection again.** MySQL: once a connection was killed (`KILL`, `wait_timeout`), releasing a pooled statement sent COM_STMT_RESET on the dead socket from an event-loop callback. The failure then hit the next query as a Revolt `UncaughtThrowable`. `MysqlStatementPool::push()` now discards a statement whose reset fails, and the `SqlStatementPool` release callback never throws. The next query gets `2006 MySQL server has gone away`, and Laravel reconnects. Postgres: a session-ending FATAL (`57P01` from `pg_terminate_backend`, `57P02`, `57P03`) is now reported as pdo_pgsql does: `SQLSTATE[HY000]: General error: 7 FATAL:  ...` followed by libpq's `server closed the connection unexpectedly` text. `PgSqlHandle` keeps the FATAL it read when the socket closed while idle, so the next query reports the same text. `PgSqlHandle` also stops watching and closes a handle whose connection went bad, because the closed libpq socket made `stream_select()` fail for every other connection on the loop.
- Fixed: `FledgePostgresPdo::quote()` no longer doubles backslashes, so `Connection::escape()` and `toRawSql()` stop corrupting values that contain `\`. It now matches pdo_pgsql: `PARAM_INT` is quoted as a string, `PARAM_LOB` becomes a hex bytea literal, and invalid UTF-8 returns false.
- Fixed: Postgres `?` to `$N` placeholder conversion now follows PDO's pgsql scanner. `??` becomes a literal `?`, which fixes `whereJsonContainsKey` and the jsonb `?|` and `?&` operators. Comments, `$tag$` dollar quotes and `E''` strings are skipped, and backslash is no longer an escape in plain strings.
- Security: `FledgePostgresPdo::quote()` now honours the server's `standard_conforming_strings` (doubling backslashes when off, falling back to a safe `E''` literal when unknown), and `inTransaction()` reports false once the server has ended the connection, matching pdo_pgsql.
- Fixed: a Postgres transaction destroyed after its connection was closed no longer throws an `UncaughtThrowable` from the event loop.

#### Error inventory

Every throwable the forked drivers raise below the shims, and what the shim now throws (what pdo_mysql / pdo_pgsql throw in the same situation).

| Driver throwable | Situation | MySQL / MariaDB (pdo_mysql) | Postgres (pdo_pgsql) |
|---|---|---|---|
| `SqlQueryError` (MySQL ERR packet) | Server error on a statement | `SQLSTATE[<state>]: <PDO description>: <errno> <message>`, code = SQLSTATE | n/a |
| `PostgresQueryError` | Server error on a statement | n/a | `SQLSTATE[<state>]: <PDO description>: 7 <libpq message>`, code = SQLSTATE; session-ending `57P01`/`57P02`/`57P03` become `SQLSTATE[HY000]: General error: 7 FATAL:  ...\nserver closed the connection unexpectedly ...` |
| `SqlQueryError("Empty query string")` | Empty query (pgsql handle) | n/a | `SQLSTATE[HY000]: General error: 7 Empty query string` |
| `SqlConnectionException` "Failed to initialize database session" wrapping `SqlQueryError` | `isolation_level`, `timezone` or `ATTR_INIT_COMMAND` fails | The inner statement error, shaped as above | n/a (session settings ride the startup packet) |
| `SqlConnectionException` "Could not connect to database server at ... after N tries" (`RetrySqlConnector`, `CompositeException` of attempts) | Connect failure | Unwrapped to the last attempt, see the rows below | `SQLSTATE[08006] [7] <libpq message>`, int code 7 |
| `SqlConnectionException` "Could not connect to tcp://...: #28000Access denied ..." (code = server errno) | Handshake ERR packet | `SQLSTATE[HY000] [1045] Access denied for user ...` (also 1044, 1049 Unknown database, 1040, 1129, 1130), int code = errno | n/a |
| `SqlConnectionException` "Connection closed unexpectedly" during handshake | Server dropped the handshake | `SQLSTATE[HY000] [2006] MySQL server has gone away` | n/a |
| `SqlException` "Connecting to the MySQL server failed" wrapping `ConnectException` | Socket refused, timed out, missing unix socket, DNS failure | `SQLSTATE[HY000] [2002] Connection refused` / `Connection timed out` / `No such file or directory` / `php_network_getaddresses: getaddrinfo for <host> failed: Name or service not known` | n/a (libpq opens the socket) |
| `TlsException` in the chain | TLS negotiation fails | `SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL` | libpq message under `[08006] [7]` |
| `SqlConnectionException` "Could not connect to PostgreSQL server" (pecl-pq) | Connect failure | n/a | `SQLSTATE[08006] [7] <libpq message>` from the previous exception |
| `SqlConnectionException` "Connection closed unexpectedly", "Connection closed after receiving an unexpected error packet", "The connection closed during the operation" | Connection dropped mid-query, or the server killed it | `SQLSTATE[HY000]: General error: 2006 MySQL server has gone away` | `SQLSTATE[HY000]: General error: 7 [FATAL:  ...\n]server closed the connection unexpectedly ...` |
| Other `SqlConnectionException` ("Connection went away", "Connection closed", libpq errors) | Connection already dead | `SQLSTATE[HY000]: General error: 2006 MySQL server has gone away` | `SQLSTATE[HY000]: General error: 7 no connection to the server`, or the FATAL the handle kept when the server closed it |
| `\Error` "The connection has been closed", "The connection to the database has been closed", "The pool has been closed", "The statement has been closed" | Use of a closed connection, pool or statement | `2006 MySQL server has gone away` | `no connection to the server` |
| `SqlException` "Pool closed before an active connection could be obtained", "The statement has been closed or the connection went away / pool has been closed" | Pool or connection shut down underneath | `2006 MySQL server has gone away` | `no connection to the server` |
| `StreamException`, `ClosedException` | Socket closed or failed | `2006 MySQL server has gone away` | `no connection to the server` |
| `CancelledException`, `TimeoutException` | Operation cancelled or timed out | `2006 MySQL server has gone away` (mysqlnd read timeout) | `SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to user request` |
| `SqlTransactionError` | Use of a committed or rolled back transaction | `There is no active transaction`, code 0, no errorInfo | Same |
| `\Error` "Parameter N missing", "... is not defined", "Named parameter ...", "Value for (un)named parameter ... missing", "Cannot mix unnamed ...", "Numbered placeholders must be sequential ..." | Bound values do not match the placeholders | `SQLSTATE[HY093]: Invalid parameter number: number of bound variables does not match number of tokens` | Same |
| `SqlException` (protocol: "Unexpected packet type", binary protocol decoding, `handleEof`), `RuntimeException` ("Decompression failed") | Protocol or decoding error | `SQLSTATE[HY000]: General error: <errno or 2027> <message>` | n/a |
| `PostgresParseException`, `SqlException` (bad response, unknown result status, prepare failure) | Protocol or array parse error | n/a | `SQLSTATE[HY000]: General error: 7 <message>` |
| `TypeError`, `ValueError`, `ArgumentCountError`, any other `\Error` | Programming or configuration error | Rethrown untouched | Rethrown untouched |
| `PDOException` | Already shaped | Passed through | Passed through |

#### Known differences

These are not implemented because Laravel core never reaches them: `fetchColumn`, `bindParam`, `FETCH_KEY_PAIR`, `query()`, `setAttribute()` after connect, and `errorInfo()` on the PDO and PDOStatement objects.

- `quote()` on Postgres caches `standard_conforming_strings` per PDO instance, so a later per-session `SET standard_conforming_strings` is not seen.
- Connections are lazy. The pool opens its first connection on the first query, so a bad password, an unknown database or a refused socket is not thrown by the connector or by calling `getPdo()` directly, but by the first query, in the same PDO shape. Laravel sees no difference, because `ConnectionFactory` already resolves the PDO lazily.

## v13.35.0.1 - 2026-10-07

### Database
- **Query errors are now PDO-shaped**: the async drivers threw `SqlQueryError` (an `\Error` with code 0 and no errorInfo), which slipped past Laravel's `catch (Exception)` in `Connection::runQueryCallback()`. Duplicate-key inserts therefore never became `UniqueConstraintViolationException`, so `createOrFirst()` and `firstOrCreate()` failed instead of returning the existing row, and deadlocks were not recognised as concurrency errors. The PDO shims (`FledgePdo`, `FledgePdoStatement`, and the MySQL/MariaDB and Postgres subclasses) now rethrow driver errors as `FledgePdoException`, a `PDOException` shaped like pdo_mysql/pdo_pgsql: message `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ...`, `getCode()` returning the SQLSTATE string, `errorInfo = [sqlstate, native code, server message]`, and the original `SqlQueryError` as previous. This covers prepare, exec, execute, fetching, and transaction begin/commit/rollback.
- `SqlQueryError` gains `getErrorCode()`, `getSqlState()`, and `getServerMessage()`; the constructor stays backward compatible. The MySQL driver fills them from the ERR packet and `PostgresQueryError` takes the SQLSTATE from its diagnostics. The MySQL message text is unchanged.

## v13.34.0.1 - 2026-10-03

### Database
- **MySQL connections over TCP set TCP_NODELAY**: the client never disabled Nagle's algorithm, so every small command packet (prepare, execute, statement close) waited on the server's delayed ACK, stalling each round trip by ~20 ms over TCP while unix sockets took ~0.2 ms. `SocketMysqlConnection::connect()` now adds `withTcpNoDelay()` to the connect context for `tcp://` connections, keeping caller-supplied options such as TLS; unix sockets are unchanged.

## v13.29.0.3 - 2026-08-27

### Database
- **Fixed a hard deadlock in the pooled statement path** (inherited from the amphp/sql-common fork): when the statement pool declined to retain a prepared statement because the pool was saturated, it dropped neither the statement nor the release closure's reference to it, so the checked-out connection never returned to the pool. With `pool_size => 1`, a second sequential prepare+execute hung forever; in general, N live prepared statements starved a pool of N connections. The decline paths now close the statement (releasing its connection immediately) and the release closure drops its reference. Reported upstream to amphp/sql-common.
- The PDO statement shim releases its previous result before re-executing (the old result used to pin a pooled connection while the new execute waited for one) and gains `closeCursor()`.
- **Fixed a Postgres handle wedge on DEALLOCATE errors**: deallocating a prepared statement that the server had already dropped (via the pool's `DISCARD ALL` reset) produced an error result whose fatal-error drain could consume responses belonging to a subsequently dispatched operation, leaving that operation awaiting a reply forever. Deallocation now sends the DEALLOCATE without routing it through result processing and tolerates failure on both the pgsql and pq backends.
- Regression coverage: statement-pool retention unit tests (upstream never exercises the retention guards; its mocks report a connection limit of 0) and watchdog-bounded `pool_size => 1` integration tests for sequential prepares and same-statement re-execute on both drivers.

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
