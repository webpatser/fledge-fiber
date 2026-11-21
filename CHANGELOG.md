# Changelog

## v13.43.0.3 - 2026-11-21

### Added
- `Fledge\Async\EventLoop\Driver\PollDriver`: a Revolt event loop driver on PHP 8.6 `Io\Poll` (epoll on Linux, kqueue on macOS/BSD), not bound by the 1024 descriptor limit of `stream_select`. Streams the backend rejects fall back to a `stream_select` set.
- `Fledge\Async\EventLoop\LoopDriver` selects and installs the event loop driver per worker via `FLEDGE_EVENT_LOOP` (`auto`, `poll`, `select`, `revolt`). `auto` uses the poll driver where available and Revolt's own choice otherwise; `select` is the rollback. `install()` refuses to swap a running or non-empty loop and must be called in the worker after fork.
- Benchmark `bench/eventloop/idle_connections.php` with results: on a Linux container with 10 idle connections, 276k round trips/s on epoll versus 189k on select.

### Changed
- PHP 8.6 is now required (`"php": "^8.6"`); PHP 8.5 is dropped. CI runs on 8.6 only.
- Dev toolchain moves to Pest 5 (PHPUnit 13), which is needed to install on PHP 8.6.
- `ConnectContext` and `BindContext` expose the PHP 8.6 socket context options as `withKeepAlive(?int $idle, ?int $interval, ?int $count)`, `withoutKeepAlive()`, `withReceiveBufferSize()` and `withSendBufferSize()` (plus `without` and getters). Redis `tcp_keepalive` now uses `withKeepAlive()` at connect instead of importing the stream into ext-sockets. The WebSocket server still sets `TCP_NODELAY` per accepted client through ext-sockets, because its listener is built from the caller's `BindContext`; set `withTcpNoDelay()` there.
- Tests: `ReflectionMethod::invoke()` is no longer called with an object for static methods, and `phpunit.xml` sets `memory_limit` to -1 so a plain `vendor/bin/pest` matches CI.

### Fixed
- TLS handshakes now wait for writable when `stream_socket_get_crypto_status()` reports WANT_WRITE, so a handshake blocked on flushing (large certificate chain, full send buffer) no longer stalls until the peer times out.
- `RevoltWaiter`'s loop-fiber stream wait uses a poll(2) `Io\Poll\Context` instead of `stream_select()`, so sockets numbered at or above 1024 are waited on correctly instead of reporting an immediate timeout.
- `Http2Driver` keeps discarding an exception raised while completing a stream that is already closed; the PHP 8.6 "return in finally" fix does not change that.
- `Http2Driver` end-of-body handling no longer returns from a `finally` block (deprecated in PHP 8.6); the stream release check moved into one condition.
- `Sync\Lock` and `ContextWorkerPool` use `SplObjectStorage::offsetSet()` and `offsetUnset()` instead of the `attach()` and `detach()` methods deprecated in PHP 8.5.

## v13.43.0.2 - 2026-11-19

### Fixed
- Redis: `ReconnectingRedisLink` now tracks an open `MULTI` in send order, so pipelined commands are covered before `MULTI` is answered. When the connection drops, the commands of a transaction whose `MULTI` reached the lost connection are no longer resent or sent on the new connection, retryable or not: they fail with `RedisInFlightCommandException`, so `FledgeRedisConnection` aborts the transaction instead of applying one command outside it and failing the later `EXEC`. `MULTI`, `EXEC` and `DISCARD` are never resent. If the connection drops while no command of the open transaction is pending, the next command fails in its place, exactly once, with `RedisInFlightCommandException`. The link leaves transaction state on `EXEC`, `DISCARD` or `RESET` (whatever their reply), on a `MULTI` that is rejected, hits the read timeout or fails without a reply, and on the reconnect that lost the transaction. Commands outside a transaction are resent as before. `WATCH` is not covered: a `WATCH` lost with the connection is not restored, so an `EXEC` on the new connection runs unguarded.
- Redis cluster: when the `MULTI` that `ClusteringRedisLink` sends lazily before the first command of a transaction throws (read timeout, lost connection), the link leaves transaction state as it already did for an error reply, instead of opening a fresh `MULTI` around the next command.

## v13.43.0.1 - 2026-11-19

### Changed
- Redis: `DEL` is now a safely retryable command, as in Laravel v13.43.0. `FledgeRedisConnection` gives it the free retry on a transient connection error, and `ReconnectingRedisLink` resends a `DEL` that was in flight when the connection dropped instead of failing it with `RedisInFlightCommandException`. A resent `DEL` can report 0 for a key the first attempt already removed. Laravel v13.43.0's new `Connection closed` lost-connection message is already covered by the existing `Connection` marker in `TRANSIENT_ERROR_MARKERS`.
- Redis, known limit: `ReconnectingRedisLink` does not track an open `MULTI`, so a `DEL` (like `SET` and the other retryable commands) that is in flight inside a transaction when the connection drops is resent outside the transaction on the new connection. Transactions that must be atomic across reconnects should not rely on the retry (fixed in v13.43.0.2).

## v13.42.0.3 - 2026-11-18

### Changed
- `pool_wait_timeout` now defaults to 30 seconds on the amphp MySQL pool (`fledge-mysql`, `fledge-mariadb`) and on the Postgres pool (`fledge-pgsql`) when the key is unset, as the native pool already did. A value of 0, '0' or '' means no limit, the old behaviour. The wait includes the connect, and on Postgres the `DISCARD ALL` reset counts against the same deadline. A timeout throws `SqlException` with code 2002, which surfaces as a `QueryException`, not as a lost connection. A pool class built directly through the async API keeps no limit unless `waitTimeout` is passed. The native pool still rejects 0.
- MySQL and Redis connects fail at once. `SocketMysqlConnector` and `SocketRedisConnector` default to a plain `DnsSocketConnector`, so a refused connect no longer retries with 2 s and 4 s of backoff (about 6 s before). Both no longer pick up a globally set `Stream\socketConnector()`. The HTTP pool, IPC and proxies keep the retrying default. The previous-exception text of a refused Redis connect is now the single-attempt message.
- Redis: `RedisSubscriber` opens or keeps no connection when the last subscription is removed during a connect or during the reconnect backoff. After an established subscription drops, refused reconnects are retried with the subscriber's backoff for up to about 6 s (`RECONNECT_REFUSED_WINDOW`); a refused first connect fails at once. A refused reconnect waits at least 0.05 s (`RECONNECT_REFUSED_MIN_DELAY`) even when the backoff strategy returns 0, so a zero backoff cannot spin.
- Livewire (`FiberHandleRequests`): unchanged reactive children are skipped as in stock Livewire (`SupportReactiveProps::shouldSkipUpdate`) and `temporarilyPropagateExceptions()` is honoured. In concurrent mode a component with reactive props runs after all earlier components of the batch finished, in batch order; other components stay concurrent.

### Fixed
- Postgres (`fledge-pgsql` on ext-pgsql, used when ext-pq is absent): closing a connection while a statement is still running, for example when `pool_wait_timeout` ends a `DISCARD ALL` against a server that stopped answering, no longer freezes the whole process. With ext-sockets loaded the socket is shut down first and the close returns at once. Without ext-sockets the caller still gets its timeout, but the link is only freed when the server answers; ext-sockets is recommended on the pgsql driver (like `tcp_keepalive`, it is not listed in `composer.json`).
- The ext-pgsql async connect now waits only for the readiness libpq asks for, so a slow or silent server no longer makes the connect loop burn a full CPU core.
- Native driver (`fledge-mysql-native`, `fledge-mariadb-native`): a fiber that holds a pinned connection with changed session state (user variables, locks) and whose pool another fiber or context replaced or disconnected, between statements or during an in-flight statement, now gets one `Illuminate\Database\LostConnectionException`; its next statement runs on a fresh session. Laravel's lost-connection retry does not swallow it. Before, the fiber continued silently on a fresh session and lost its state. Clean leases still move to the new pool silently, and a genuinely lost server connection still retries as stock.
- MySQL PDO shim: the session check judges `MATCH (columns) AGAINST (...)` (what `whereFullText` emits) as clean, so it no longer pins a connection. `against(` anywhere else stays dirty.
- `LOAD DATA LOCAL INFILE` without ext-parallel, ext-uv and ext-eio: `DefaultContextFactory` called undefined functions; it now forwards the child's output itself.
- Tests: user-defined Postgres types (enum, composite) created after connect come back as strings on pgsql and pq, as with `pdo_pgsql`, and the type cache is per database.
- `docker-compose.yml`: the httpbin healthcheck uses `python3`, as the image has no `curl`.
- Tests: `PdoErrorParityTest` skips the cases that need a second session when mysqli (or pgsql) is not loaded.

### Upgrade notes
- A pool that waited without limit now fails after 30 s with a `QueryException` (code 2002) on `fledge-mysql`, `fledge-mariadb` and `fledge-pgsql`. Set `'pool_wait_timeout' => 0` on the connection for no limit.
- A refused MySQL or Redis connect now fails at once. There is no config switch; the connectors use the retrying socket connector only when you pass one, for example `new SocketMysqlConnector(new RetrySocketConnector(new DnsSocketConnector()))` through the async API.
- Known limit: in-process browser tests (the Pest browser plugin) on the native driver cannot use `RefreshDatabase` or `DatabaseTransactions`, because each request fiber gets its own connection and cannot see the test's uncommitted transaction. Use `DatabaseTruncation`, or run browser tests on the stock driver. This is not new in this release.

## v13.42.0.2 - 2026-11-18

### Changed
- MySQL (`fledge-mysql`, `fledge-mariadb`): `beginTransaction()` on the PDO shim sends a plain `START TRANSACTION`, so transactions run at the session's isolation level (the server default, or the connection's configured `isolation_level`), as with `pdo_mysql`. Before, every transaction ran at READ COMMITTED and a configured level was overridden. An app that relied on READ COMMITTED must set `'isolation_level' => 'READ COMMITTED'` on the connection.
- MySQL (`fledge-mysql`, `fledge-mariadb`): session state now persists between statements of one fiber. Each fiber scope (a fiber, or the main context) leases one pooled connection on its first statement and its transactions run on it. When another caller waits for a connection, a pin whose owner is between statements and has run only statements proven not to change the session goes back to the pool and the owner leases again on its next statement. A pin whose session may have changed (`SET`, user variables, temporary tables, `GET_LOCK`, `LAST_INSERT_ID()` and anything the check cannot prove clean) stays until the scope ends and is then reset: `COM_RESET_CONNECTION`, then one query that re-applies `SET NAMES`, `sql_mode`, the connector's session statements (`isolation_level`, `timezone`, `ATTR_INIT_COMMAND`) and `USE` of the configured database. That is two round trips per release of a changed session; a clean pin goes back without any. A server that does not know `COM_RESET_CONNECTION` (error 1047) gets its connection closed on release instead, with one `error_log` line per process; a connection whose reset fails, or that still has an open transaction, is closed too.
- Redis: `psubscribe()` callbacks receive the payload and the matched channel, as phpredis does (before, a `[payload, channel]` pair and the pattern). With several channels the callbacks run one at a time, in the order the subscriber received the messages.
- HTTP client: a Guzzle `timeout` bounds the whole request, including the wait for a pooled connection and the connect. Before, the transfer timeout only started once a stream existed.
- MySQL: BIT columns in prepared-statement results are integers (big-endian, as mysqlnd decodes them); an unsigned BIGINT of 2^63 and above is a decimal string. Text-protocol results of unprepared statements are unchanged (BIT stays raw bytes).
- Postgres async API: `??` outside a literal is sent as a literal `?`; placeholders inside `--` and block comments, dollar-quoted bodies and `E''` strings are ignored.
- HTTP client (Guzzle handler): a request body of 1,000,000 bytes or more, or a body of unknown size sent with a `Transfer-Encoding` header (chunked), is streamed in 64 KiB reads instead of being buffered whole. Smaller bodies stay buffered. A streamed body cannot be replayed, so a user-supplied async client wrapped in `RetryRequests` fails its retry.
- Redis: blocking commands (`BLPOP`, `BRPOP`, `BRPOPLPUSH`, `BLMOVE`, `BLMPOP`, `BZPOPMIN`, `BZPOPMAX`, `BZMPOP`, and `XREAD` / `XREADGROUP` with `BLOCK`) run on a dedicated connection, so a blocked pop no longer holds up the other fibers on the shared one (as with phpredis, where it blocks only its own process). `FledgeRedisConnection::BLOCKING_IDLE_LIMIT` (2) idle dedicated connections are kept for reuse; only idle ones are capped, so N concurrent blocking calls open N sockets. The dedicated connections follow `SELECT`. A pipeline that holds a blocking command runs its commands in order. Inside this fiber's `MULTI` such a command stays on the transaction connection, as Redis only queues it.
- MySQL PDO shim (`fledge-mysql`, `fledge-mariadb`): the session check is stricter and wider at once. Now judged clean: comments and optimizer hints, sized casts (`CAST(x AS DECIMAL(10,2))`), math, date and time, JSON reader and read-only spatial functions that are built-in on both MySQL 8 and MariaDB 11, and CTE column lists. Now judged dirty: any backslash in the query text, `/*!` executable comments, `--` followed by a control character, names that are not built-in on both servers (for example `point()`, `MATCH ... AGAINST`, `ANY_VALUE`, `MEMBER OF`, `uuid_to_bin`, `regexp_like`, `json_query`) and a regex engine failure. Triggers, views and stored code stay invisible to a text check.
- MySQL PDO shim: a prepared statement that is executed again is reset on the server (`COM_STMT_RESET`) only when long data from `bind()` still waits there; before, every re-execute and every release to the statement pool sent a reset.
- Native PDO pool (`fledge-mysql-native`, `fledge-mariadb-native`): session state no longer passes from one fiber to the next, and lasts for the fiber's scope (the whole request or job callback in a fiber, until disconnect in the main context), as with one stock PDO. A lease whose session may have changed is pinned to its fiber: the fiber's following statements on that slot (write, read and direct each pin on their own) run on the same connection, so `Schema::disableForeignKeyConstraints()` followed by separate statements (`dropAllTables()` behind `migrate:fresh`, `db:wipe` and `RefreshDatabase`) and `SET @x` followed by `SELECT @x` work as stock. When the fiber's callback ends the pinned connection is discarded instead of returned and the pool opens a fresh one for a later lessee (`pdo_mysql` has no reset command); in the main context (artisan commands, `queue:work`, Horizon, Octane on Swoole, RoadRunner or FrankenPHP, PHPUnit) the pin lasts until `DB::disconnect()`, `purge()` or a reconnect, so session state carries from job to job as with stock Laravel's single PDO. A pinned connection counts against `pool_size` until the fiber's scope ends; the pinning fiber's own statements reuse it, other fibers wait up to `pool_wait_timeout`. Dirty is decided per statement in `NativePdo::prepare()`, `exec()` and `query()` with the same classifier as the PDO shim (plus the read-backs `CONNECTION_ID()`, `DATABASE()`, `FOUND_ROWS()`, `ROW_COUNT()` and savepoint statements, which stay clean), and `setAttribute()` is always dirty. `quote()`, `getAttribute()`, `lastInsertId()`, `inTransaction()`, `beginTransaction()`, `commit()` and `rollBack()` are clean, so Telescope or Debugbar cause no reconnects. A clean lease goes back without a round trip; a fiber that changes its session costs one extra connection for its whole scope, not one per statement.
- Postgres async pool: `DISCARD ALL` runs on checkout only for a connection whose session may have changed (a new one, or after a statement the classifier cannot prove clean: `SET`, `LISTEN`, `PREPARE`, temporary tables, advisory locks, user functions, an abandoned or failed transaction). Before, every checkout ran it. `INSERT`, `UPDATE`, `DELETE` and `MERGE` count as dirty because a trigger can do anything a function can, so a transaction with DML costs a `DISCARD ALL` on the next checkout. Function names with non-ASCII characters are never treated as built-ins. Triggers, views and rules stay invisible to a text check.
- **Upgrade notes**
  - A native-pool fiber that issues `SET`, user variables, temporary tables, `GET_LOCK`, any statement the classifier cannot prove clean, or `setAttribute()` now costs one reconnect when its scope ends, not one per statement. Session state lasts for the fiber's request or job callback (until disconnect in the main context): `statement('SET @x=1')` followed by a separate `select('SELECT @x')` returns the value for the same fiber, another fiber never sees it, and the connection is discarded at the end instead of returned to the pool. A raw `DB::getPdo()` that is never used holds its connection until the fiber's outermost scope ends, and a raw PDO held after a discard stays open outside the pool count.
  - Transactions on the MySQL PDO shim no longer run at READ COMMITTED unless the server default or `isolation_level` says so (first item above). The async pool API `MysqlConnectionPool::beginTransaction()` still applies the pool's own level (READ COMMITTED by default).
  - A fiber whose session changed holds its pool slot until its scope ends. On a full pool, a second fiber waits for that slot with no timeout unless `pool_wait_timeout` is set (see Added), and a fiber with a changed session that awaits a child fiber needing a connection waits forever. Size `pool_size` for the number of concurrent fibers that change session state, or keep such fibers short.
  - Code that read `psubscribe()` callback arguments as `([payload, channel], pattern)` now gets `(payload, channel)`.

### Security
- HTTP server: cookies are parsed once per request, so a request with thousands of `Cookie` header lines no longer causes quadratic work that stalls the event loop. Cookie header detection is an exact match.
- MySQL: when TLS is configured and the server greeting does not advertise SSL support, the connection now fails with `SqlConnectionException` before any credentials are sent, instead of silently continuing unencrypted.
- MySQL: a server RSA public key is accepted only after the client requested one; a key packet that was not requested fails the connect (`caching_sha2_password` and `sha256_password`). A key pinned with `withKey()` is used for the RSA password encryption and is never replaced by a server-supplied key.
- MySQL: `quote()` always doubles single quotes, honours the server's `NO_BACKSLASH_ESCAPES` mode and escapes per character for the multi-byte charsets big5, gbk, gb18030, sjis and cp932.
- MySQL: LOCAL INFILE follows mysqlnd's rules (`withLocalInfile()` and `withLocalInfileDirectory()`); a refused or failed request ends the transfer with the empty packet, so the connection stays usable, and the query fails with client error 2068.
- HTTP server: the header block of a multipart part is capped (default 16384 bytes, `partHeaderSizeLimit` on `StreamingFormParser`); a larger one is answered with 400. The idle timeout is re-armed after a HEAD response, so keep-alive connections are closed when idle.
- HTTP client: an HTTP/2 server that floods PING, SETTINGS or pushed streams without reading the replies gets the connection closed with `ENHANCE_YOUR_CALM` once 1000 control frames wait in the write queue. Redirects no longer carry the request body. The HTTP/1 chunked decoder caps the chunk-size line at 1024 bytes, requires CRLF after chunk data and rejects chunk sizes over 15 hex digits.
- WebSocket server: each client has a send queue cap (16 MiB and 65,536 messages by default); overflow closes the client with 1008 (policy violation) and fails its pending sends.
- Livewire: the payload size limit is enforced on the actual body length instead of the `Content-Length` header.
- Redis: the resp3 extension parser is used only from resp3 0.2.0 (`Resp3ExtensionParser::MINIMUM_VERSION`). Before 0.2.0 the extension returns an error nested in an array reply as a plain string, so a failed command in `EXEC` (for example a failed `SELECT` that the database tracking would record) read as a success; with an older extension the pure-PHP parser is used, with one warning per process. CI installs `webpatser/php-resp3:^0.2`.

### Added
- Connection config key `pool_wait_timeout` for `fledge-mysql` and `fledge-mariadb` (`MysqlConnectionPool` constructor argument `waitTimeout`, `SqlCommonConnectionPool::getWaitTimeout()`): seconds a caller may wait for a connection, the wait on a full pool and the connect included. Unset or 0 means no limit, which is the unchanged default. A timeout throws `SqlException` with code 2002, which surfaces as a `QueryException` and is deliberately not treated as a lost connection, so Laravel does not reconnect into a second pool. The key has three meanings: the amphp MySQL pool (this one, no limit by default), the native pool (default 30 s, a value of 0 or below is rejected with an `InvalidArgumentException` when the pool is built) and Postgres (ignored).
- Redis: `FledgeRedisConnection::xread()` and `xreadgroup()` with the phpredis signatures; both return `[]` on a `BLOCK` timeout or nothing to read, as phpredis does. `RedisClient::closeConnection()` and `RedisClient::rememberDatabase()`.
- `MysqlConfig::withSecureKeyRetrieval()` and the connection config key `secure_key_retrieval` (default false): without TLS and without a pinned key, authentication that needs the server's RSA key fails instead of fetching it.
- `MysqlConfig::withLocalInfileDirectory()`. The connector maps `Pdo\Mysql::ATTR_LOCAL_INFILE`, `ATTR_LOCAL_INFILE_DIRECTORY` and `ATTR_SERVER_PUBLIC_KEY` (a PEM file path) from `options`.
- `WebsocketClientGateway` constructor arguments `maxQueuedBytes` and `maxQueuedMessages` (null for no limit); `Http2ConnectionProcessor` constructor argument `maxQueuedControlFrames`.

### Fixed
- HTTP/1 client: a request whose write fails (a reset, a body that throws) is never reused: the connection is closed, also when the response was already complete. An early response (for example a 413 sent before the body was written) is still delivered in full before the connection closes.
- HPACK (nghttp2 binding): the deflate and inflate contexts are freed when the object goes away or its constructor fails (`nghttp2_hd_deflate_del()` and `nghttp2_hd_inflate_del()` are declared in `fledge-hpack.h`); before, they leaked. Cloning and serializing the object is forbidden.
- Stream parser (`Fledge\Async\Stream\Parser`): feeding it in small chunks no longer joins and re-slices the whole buffer on every push. A string delimiter is searched only in bytes not searched before, and the unconsumed remainder is sliced once per push. Output and the `maxDelimitedLength` limit are unchanged.
- Redis: `disconnect()` fails blocking calls in flight with the lost-connection exception instead of leaving them blocked, and they are not retried; it has no effect on a blocking call on a cluster link.
- Redis: `SELECT` is tracked reliably. A reconnect selects the database again, also for a `SELECT` queued in `MULTI` (applied only when its own `EXEC` entry succeeded) and for `executeRaw(['SELECT', n])`; a `SELECT` that Redis answered with an error or `QUEUED` changes nothing.
- Redis: a link reconnects lazily on the next command after `QUIT`, a server-side close or a closed connection, instead of eagerly opening a socket nobody uses. This fixes a socket leak.
- Postgres (pgsql extension): waiting for the rest of a result goes through the poll watcher instead of a blocking `pg_get_result()`, so a slow query no longer stalls the event loop; `send()` reports a connection libpq already knows is bad as a lost connection. A failed `notify()` leaves its connection to be reset on the next checkout (a lost connection is closed).
- Postgres (pq extension): when draining after an error fails, the lost connection is reported instead of the query error; the error of a later statement of a multi-statement query that nobody reads is no longer reported as unhandled; waiting for a busy handle no longer spins on a completed future.
- Postgres (pq extension): `PqConnection::connect()` sets `nonblocking` and `unbuffered` only after the connect succeeded, so Postgres connect errors (refused, bad password, unknown database, TLS failure) keep libpq's `connection to server at "host", port N failed: ` prefix, as `pdo_pgsql` reports them. Before, with libpq 15 or newer, setting them early cleared libpq's error buffer and the prefix was lost.
- Pool fairness: waiting on a connection pool (`SqlCommonConnectionPool::pop()`) and on a busy pq handle yields once when the awaited future is already complete, so a newer caller cannot take a connection ahead of the caller that waited first and a loop cannot spin. `ParallelFilesystemDriver` no longer starts more workers than the worker limit when several calls ask at once. A second `join()` on a parallel context after a successful one returns the exit result instead of looping.
- Livewire: `fledge-livewire.concurrent` now defaults to false (config and code fallback), because Blade keeps section and stack state process-wide and concurrent renders can mix output. Opting in is at your own risk.
- Redis: `pipeline(callable)` passes a pipeline to the callback and returns the reply list like phpredis; pipeline replies are returned in command order.
- Guzzle handler: no response body cap unless `max_body_size` is given; `timeout` 0 means no timeout; `read_timeout` maps to the inactivity timeout; `connect_timeout` is capped at `timeout`, and without either the connect limit is curl's 300 s default.
- Redis: an error nested inside an array reply (for example one failing command in MULTI/EXEC) is returned in position as a `QueryException` instead of throwing in the parser and tearing down the shared connection. A top-level error reply outside a transaction still throws `QueryException`, where phpredis returns `false`. Such a failed command is `false` in its slot in `transaction()`/`exec()`, `eval` and raw array replies (as in phpredis); the low-level client still carries the exception.
- Redis: reply shapes in pipelines match phpredis: a failed command, a nil reply and nested nils (`MGET` elements) are `false` in their slot, and a `BLPOP` or `BRPOP` timeout is `[]`. Connection errors still throw.
- Redis: `exec()` returns one reply per command with failed commands, nil replies and nested nils as `false`; a WATCH-aborted `EXEC` returns `false`; a command rejected while queueing returns `false` and the following `EXECABORT` makes `exec()` return `false`.
- Redis: while a `MULTI` is open, queued commands (also through `setnx`, `hsetnx` and the scan wrappers) return the connection instead of `'QUEUED'`, so `multi()->incr()->exec()` chains.
- Redis: `eval` inside an open `MULTI` is sent as `EVAL` instead of `EVALSHA`, so the `NOSCRIPT` fallback is not needed; a nil result and nils inside result tables are `false`.
- MySQL: TLS is requested when any of `ATTR_SSL_CA`, `ATTR_SSL_CAPATH`, `ATTR_SSL_CERT`, `ATTR_SSL_KEY` or `ATTR_SSL_CIPHER` is present in the options, whatever its value (`null` and `''` included), matching `pdo_mysql`; `ATTR_SSL_VERIFY_SERVER_CERT` alone never enables TLS.
- MySQL: RSA password encryption for passwords of 20 or more characters. The `sha256_password` handshake response no longer gets a truncated length for an RSA block over 255 bytes; when the server does not allow the length-encoded form the connect fails instead.
- WebSocket server: a send to a closed client fails at once with `WebsocketClosedException` instead of hanging.
- HTTP server form parser: urlencoded bodies split across reads, keys without `=`, a trailing key named `0`, the field count limit (off by one) and multipart bodies arriving in small reads.
- MySQL PDO shim: `SET`, user variables, temporary tables, `GET_LOCK` and `LAST_INSERT_ID()` follow the fiber that issued them (see the session pin under Changed). `PDO::lastInsertId()` and `insertGetId()` return the calling fiber's id, also when another fiber inserted in between. `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false` streams rows (a second query while rows are unread fails with error 2014, as in `pdo_mysql`); results are buffered by default, as in `pdo_mysql`. The configured database is selected again after a session reset.
- Native PDO pool: `reconnect()` no longer tears down the shared pool (the stock rebuild only runs when no slot holds a pool, so a read-only workload and the read pool survive a lost connection). Per-fiber lease and transaction state, and the per-fiber after-commit records, no longer leak into the next callback on a reused fiber: an abandoned transaction's connection is discarded (the server rolls it back) and its after-commit callbacks are dropped; an idle lease goes back to the pool.
- Postgres: a failed `DISCARD ALL` on checkout no longer wedges the pool (the connection is dropped and its slot freed). Listener bookkeeping: the pool counts a listener before awaiting the listening connection, releases the hold exactly once, also for a listener that stopped listening, and replaces a listening connection that died (its listeners fail with the connection error, new ones get a fresh connection). The type cache is kept per database instead of per host, port and user. `whereJsonContainsKey` and other queries with `??` and bindings work: the escape is passed on to the executor, which unescapes it. With the pq extension the later results of a multi-statement query are read, and the connection is drained after an error.
- Redis: `subscribe()` and `psubscribe()` with several channels subscribe to all of them (before, only the first was consumed). The subscriber backs off before reconnecting (the default strategy, 0.1 s doubling to 1 s, reset once a subscription is confirmed or a message arrives) instead of reconnecting in a tight loop, a rejected SUBSCRIBE (for example `-NOPERM`) fails only the subscriptions of that channel, and it no longer reconnects once more after the last unsubscribe. Key positions for prefixing and cluster routing: `BITOP` (every key after the operation), `BLMPOP` and `BZMPOP` (`numkeys` second), `LMPOP`, `ZMPOP`, `SINTERCARD`, `ZINTERCARD`, `ZUNION`, `ZINTER` and `ZDIFF` (`numkeys` first), `ZRANGESTORE` (two keys), `XREAD` and `XREADGROUP` (`STREAMS` found after the `GROUP`, `COUNT` and `BLOCK` arguments), `XGROUP`, `XINFO`, `OBJECT` and `MEMORY USAGE` (the key after the subcommand), `GEORADIUS` and `GEORADIUSBYMEMBER` (the `STORE` and `STOREDIST` destination) and `MIGRATE` (the key, or the keys after `KEYS`); `SORT_RO` and `SUBSTR` are no longer treated as two-key commands.
- DNS: a failed lookup for a name with a trailing dot is no longer cached forever (the shared query was registered under the name as asked and removed under the normalized one, so the failed future stayed). The shared query no longer takes the first caller's cancellation, so cancelling one caller does not fail the others waiting on the same name; each caller awaits with its own cancellation.
- Streams: a write to a peer that closed fails with a `StreamException` instead of hanging (a TLS stream writing zero bytes without an error is checked with `feof()`). A read on a TLS stream checks `feof()` before the direct read, so a record that `feof()` moved into the OpenSSL buffer is no longer left waiting for a socket event.
- HTTP/2 server: response DATA is no longer sent twice or out of order when window updates arrive during a write. The stream buffer is updated before the write and all frames of one write go out in one `write()`.
- HTTP/2 client: control frame replies (PING ACKs and similar) are batched into one socket write instead of one write per frame, the read side lets the writer run after a chunk that queued replies, and the flood limit counts only replies not yet written. Before, on Linux a burst of PINGs from a server that was reading its replies could trip the client's own flood limit and close the connection with ENHANCE_YOUR_CALM.
- HTTP client pool: a request waiting for a connection honours its cancellation, and a connection is no longer handed to a waiter whose request was cancelled (it goes to the next one).

## v13.42.0.1 - 2026-11-11

### Fixed
- A lost-connection commit failure no longer drops the shared native PDO pool. Laravel v13.42.0 nulls the connection's PDO in `handleCommitTransactionException`; `UsesNativePdoPool` now restores the pool and discards only the failing fiber's write lease, so other fibers' transactions continue and the dead PDO is never reused.
- This release is required with fledge-framework v13.42.0.1 and later (the framework declares a conflict with older fiber), and it stays compatible with earlier v13 frameworks.

## v13.40.0.4 - 2026-11-08

### Changed
- `RevoltWaiter` is now the canonical waiter; php-fiberio 0.3.0 no longer ships its own.

### Fixed
- The Revolt event loop no longer busy-spins on idle timers under the fiberio sleep hook. `RevoltWaiter` reported a timer wait in the loop fiber as woken, so Revolt's idle `usleep()` returned at once.

## v13.40.0.3 - 2026-11-04

### Fixed
- **Re-executing a prepared statement through `FledgePdo` no longer returns wrong values on the pure-PHP MySQL driver (`fledge-mariadb`).** A second `execute()` could send NULL for a bound parameter, glue the old and new values together (`U1U2`), or reuse the old value. `FledgePdoStatement` now follows `pdo_mysql`: bound values stay for the next `execute()`, an array replaces them, and a rebind takes effect. A single execute costs no extra round trip.
- **A connection or transaction statement no longer sends NULL when re-executed without rebinding.** The server drops long data on every execute, so such a statement now needs a new `bind()`, and without one `execute()` throws `Parameter 0 missing`. An execute that can't be encoded also drops the long data the server still holds. A raw `MysqlStatementPool` used without `FledgePdo` is unchanged: it replays its binds on every execute, and a new `bind()` appends unless `reset()` runs first.
- **Repeated named parameters now bind every position.** `SELECT :a, :a` bound the first position twice and left the other empty, also when mixed with positional parameters.
- **A bound parameter that also gets an arg no longer shifts the later values.** The server skips the value of a parameter with long data, so the arg landed on the next parameter. The bound value now wins and the arg isn't sent.

## v13.40.0.2 - 2026-11-04

### Fixed
- **Prepared statements with long parameter lists no longer fail at random with error 1210 or 1835 on the pure-PHP MySQL driver (`fledge-mariadb`).** `execute` could read the parameter definitions before they had all arrived and size its packet from a partial list. It now reads them when its queued task runs.
- **The driver no longer dies with `Event loop terminated` when a write suspends on a full socket buffer.** The socket stays referenced while any write is in flight, and is only unreferenced once nothing is pending.
- **Lost connections and failed writes now reach waiting commands as a connection exception.** They used to hang or surface as an unhandled error on the loop. Commands still queued fail when the connection goes.
- **A parameter that can't be encoded now fails only its own `execute()`.** It used to kill the connection and leave the caller hanging.

### Changed
- Binding positional parameters no longer rescans the parameter list, which cuts the time from first bind to last write by about 90% on 2813 parameters.

## v13.40.0.1 - 2026-11-04

### Fixed
- **A lost connection during rollback no longer drops the shared native PDO pool.** Laravel 13.40.0 makes `handleRollBackException` null `$this->pdo` on a lost connection, and native connections keep the pool shared by every fiber in that slot, so one fiber's failed rollback killed the healthy transactions of the others. `UsesNativePdoPool` now overrides the handler to discard only the calling fiber's write lease. A raw PDO keeps the stock behaviour.

## v13.39.0.1 - 2026-10-28

### Changed
- Mirror 'Broken pipe' in the Redis lost-connection retry list (Laravel 13.39.0).

## v13.38.0.4 - 2026-10-25

### Fixed
- **`DocumentRoot` no longer answers `304 Not Modified` for a file that changed since `If-Modified-Since`.** The comparison was inverted, so browsers kept serving a stale copy until a forced refresh. It now sends 304 only when the file's mtime is not newer than the header, and ignores `If-Modified-Since` when `If-None-Match` is present (RFC 9110 13.1.3).

## v13.38.0.3 - 2026-10-24

### Added
- **`createRedisClusterClient()` helper** next to `createRedisClient()`: builds a cluster-routing `RedisClient` from a list of seeds (`host:port`, URI or `RedisConfig`), with optional endpoint config and parser factory. Nodes learned from the topology reuse the first seed's auth, TLS and timeout options; unix-socket seeds and seeds selecting a database other than 0 are rejected. `RedisConfig::withEndpoint()` points a config at another host and port.
- **The HTTP/2 low-value frame flood limit is configurable.** `Http2Driver`, `Http1Driver` (h2c upgrade) and `DefaultHttpDriverFactory` accept `lowValueFrameLimit` (default `HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT`, 5000) and `lowValueFrameWindow` (default `HttpDriver::DEFAULT_LOW_VALUE_FRAME_WINDOW`, 10 seconds). Non-positive values throw a `ValueError`. `Http2Parser` takes the same two constructor arguments; its `LOW_VALUE_FRAME_LIMIT` and `LOW_VALUE_FRAME_WINDOW` constants stay as deprecated aliases of the `HttpDriver` defaults.

### Changed
- **The bundled MIME type list is refreshed** from the symfony/mime map (241 to 1833 extensions, every existing extension kept). Extensions already in the list keep their type unless the new one is the IANA registration or the established browser type (`cer`, `p12`/`pfx`, `p7m`/`p7c`/`p7s`, `ttc`, `jpm`, `stl`, `cat`, `wrl`/`vrml`, `exe`/`dll`, `xsl`/`xslt`, `mid`, `jfif`). `js`/`mjs` are `text/javascript`, fonts are `font/woff`, `font/woff2`, `font/ttf` and `font/otf`, `xhtml` is `application/xhtml+xml`, `opus` is `audio/opus`, `m3u8` is `application/vnd.apple.mpegurl`, `ts` is `video/mp2t`, and `webp`, `avif`, `wasm`, `webmanifest`, `jsonld` and friends are now known.
- **`DocumentRoot::loadMimeFileTypes()` parses dotted, plus and dash subtypes in full.** `application/vnd.ms-excel` was truncated to `application/vnd`.

## v13.38.0.2 - 2026-10-24

### Security
- **The native MySQL client only answers `mysql_clear_password` over TLS or a unix socket.** A server (or a man in the middle) could switch a plain TCP connection to the cleartext plugin and read the password. Over unencrypted TCP the connect now fails with a `SqlConnectionException` and the password is never sent.
- **Redis `MULTI`/`EXEC` blocks are isolated per fiber.** All fibers share one Redis link, so a command from fiber B issued while fiber A had `multi()` open was queued inside A's transaction: B got `QUEUED`, its write was dropped on `DISCARD`, and A's `exec()` result contained B's reply. `FledgeRedisConnection` now holds a fiber lock from `multi()`/`transaction()` until `exec()`/`discard()`; other fibers wait for it. A pipeline inside the owning fiber's transaction runs on that fiber. If a command inside the transaction fails on the connection (lost link, read timeout) the lock is released with a best-effort `DISCARD`. A `multi()` that is never closed for other reasons, or a transaction callback that waits on child fibers issuing Redis commands, still blocks other fibers on that connection.
- **HTTP client hardening.** A redirect that changes the scheme (`https` to `http` on the same host) now counts as cross-origin, so `FollowRedirects` drops the application headers (`Authorization`, `Cookie` and the rest) on it. `FledgeHandler` marks its requests with `FollowRedirects::ATTRIBUTE = false`, so a fixed client built with transport redirects no longer follows a second time behind Guzzle's `RedirectMiddleware`.
- **HTTP/1 server hardening.** Requests carrying both `Content-Length` and `Transfer-Encoding` are rejected with 400 (request smuggling); every chunk must end in CRLF; `Session` no longer adopts a client-supplied id that has no stored data (session fixation) and issues a fresh id instead; `DocumentRoot` answers 416 for more than 10 ranges, overlapping ranges, or a multi-range set larger than the file.
- **HTTP/2 server hardening.** DATA that overruns the connection or stream receive window ends in `FLOW_CONTROL_ERROR` (new streams also start with the window the server advertised, not the peer's `INITIAL_WINDOW_SIZE`); more than 5000 low-value frames (PRIORITY, unknown, empty non-final DATA, empty CONTINUATION) in 10 seconds closes the connection with `ENHANCE_YOUR_CALM`; `content-length` values such as `0abc` or `1e3` are rejected.
- **WebSocket permessage-deflate no longer inflates a compressed frame without bounds.** `Rfc6455Parser` counted only the compressed bytes toward `messageSizeLimit` and inflated each frame in one `inflate_add()` call, so a 1 MB frame of deflated zeros became a ~1 GB string and a `memory_limit` fatal took down the event loop (only with `Rfc7692CompressionFactory` enabled). The parser now inflates in slices sized from the remaining budget and closes with 1009 once the decompressed message exceeds `messageSizeLimit`.
- **The RESP parser caps what a Redis server can make the client buffer.** `RespParser` throws a `ProtocolException` for a bulk string over 512 MiB, an array over 1,000,000 elements, nesting deeper than 100 levels, and a line that stays unterminated past 1 MiB (via the new optional `maxDelimitedLength` / `onOverflow` arguments on `Stream\Parser`). Limits match php-resp3's defaults.
- **The native MySQL client caps the payload it buffers from the server.** Payloads above `MysqlConfig::DEFAULT_MAX_PACKET_SIZE` (1 GiB, the ceiling of MySQL's `max_allowed_packet`) fail with a `SqlConnectionException`; tune it with `MysqlConfig::withMaxPacketSize()` or the new `maxPacketSize` constructor argument.
- **Livewire constraint raised to `^4.4.8`** (GHSA-mhf7-xp6r-6qrc) in `require-dev` and the `suggest` hint. Applications using Livewire should update to 4.4.8 or later.

### Fixed
- **A stream reset before its HTTP/2 request exists** (for example a duplicate `content-length`) no longer leaves an unobserved future that an event loop without an error handler turns into a server crash.
- **Static file suffix ranges** (`bytes=-N`) return exactly the last N bytes, clamped to the whole file (previously one byte too many, and a negative start when N exceeded the size).
- **`DocumentRoot` ships its default mime type file again.** It was missing, so `onStart()` failed without an explicitly loaded one.
- **The default session id generator no longer needs `paragonie/constant_time_encoding`**, which is not installed; it uses ext-sodium when loaded.
- **Fragmented compressed WebSocket text split inside a multibyte character decodes correctly.** The bytes held back for UTF-8 validation were prepended to the next compressed payload instead of its decompressed output.

### Changed
- **Header names and values are validated in production.** CR, LF, NUL (or anything else `HttpMessage` refuses) now throw `InvalidHeaderException` on the client side, where the old `assert()` was compiled out. On the server an HTTP/1 request with such header bytes (for example 0xFF) gets a 400, and an HTTP/2 stream with CR, LF, NUL or another control character in a header is reset with `PROTOCOL_ERROR`, instead of the whole connection being dropped.
- **HTTP/2 header list limit is 32 KiB.** The enforced header list size now equals the advertised `SETTINGS_MAX_HEADER_LIST_SIZE` (the configured `headerSizeLimit`, default 32 KiB); previously 16 KiB was enforced while more was advertised.
- **`FledgeSymfonyHttpClient` caps a buffered response body at 64 MiB by default.** Raise or lift it with the `$maxBodySize` constructor argument or `extra: ['max_body_size' => N]` per request (0 for unlimited); previously the body size was unlimited.
- **Strict unserialize is an explicit opt-in.** `RedisCache`, `RedisParcel`, `SharedMemoryParcel`, `RedisSessionStorage`, `LocalSessionStorage` and `StreamChannel` keep unserializing any class by default, so stored Carbon instances, enums and DTOs still round-trip. When the backing Redis, shared memory segment or stream peer is not fully trusted, pass `new NativeSerializer([Foo::class, ...])` (or `new NativeSerializer([])` for no objects) as the `serializer` argument so a forged payload cannot instantiate gadget classes. The Laravel integration does not use these classes: Laravel sessions and caches on the Fledge Redis driver keep honouring `cache.serializable_classes` through Laravel's own stores.
- **`mysql_clear_password` over plain TCP now fails the connect** (see Security). Use TLS or a unix socket for accounts on a cleartext auth plugin.

## v13.38.0.0 - 2026-10-21

### Changed
- **Aligned with Fledge framework v13.38.0.1 (Laravel v13.38.0).** No code changes. Reviewed the upstream database changes: the new `Connection::usingWritePdo()` works with the native pools because `UsesNativePdoPool::getReadPdo()` checks `readOnWriteConnection` on every call, the `@` on `setPdo(null)` in `disconnect()` stays compatible with the pool's untyped setters, and the new `MySqlGrammar::compileForeignKeys()` filters are inherited unchanged. `readOnWriteConnection` is shared per connection, not per fiber, so a `usingWritePdo()` callback that suspends routes other fibers' reads to the write pool until it returns, which matches stock Laravel.

## v13.36.0.4 - 2026-10-21

### Fixed
- **Fire-and-forget `async()` calls no longer trip `#[\NoDiscard]`.** `PostgresConnectionListener::__destruct()`, `QueuedWritesFile::__destruct()`, `StreamChannel` (the `finally` that releases the lock), `ConcurrentIterableIterator::__construct()` and the two Windows `SocketConnector` helpers discarded the `Future` from `async()` as a bare statement. PHP emitted a warning for each, which an application error handler (Laravel's, for one) turns into an `ErrorException`, in a destructor or `finally` block that skips the rest of the cleanup. Each call now ends in `->ignore()`.

## v13.36.0.3 - 2026-10-14

### Fixed
- **Elasticsearch clients no longer lose their host for good after one network error.** `ElasticClientFactory::builder()` set no node pool, so elastic-transport fell back to `SimpleNodePool` with `NoResurrect`: the first timeout or refused connection marked the only host dead and every later request in that process failed with `NoNodeAvailableException: No alive nodes` until the worker or server restarted. The builder now uses the new `Fledge\Fiber\Search\ResurrectingNodePool`, which keeps round-robin across hosts, skips a dead host for 5 seconds (counted from the failure) and then tries it again. When every host is dead and none is past its window the pool throws `NoNodeAvailableException` instead of handing out a dead host, so a failing single-host setup fails fast: the first failure costs one timeout and marks the host dead, the transport's retries inside the same request (elastic-transport/pdphilip default 3) and every request during the next 5 seconds fail without an HTTP attempt, and after the window the host is tried again and, on success, serves normally. Multi-host setups still fail over to an alive host within the same request. Tune it per connection with `resurrect_after` (seconds), or pass `node_pool` (a `NodePoolInterface` instance or class name, or `false` for the transport default); both are read at the top level of the connection config or under `options`. A node pool set on the returned builder with `setNodePool()` still wins.

## v13.36.0.2 - 2026-10-14

### Added
- **`Fledge\Async\FiberContext`**: a capture/restore hook for `async()`. Libraries call `FiberContext::register(name, capture, restore)`; `async()` runs every `capture` in the parent fiber before queueing the closure and runs the matching `restore` in the child fiber before the closure starts. A `restore` may return a cleanup closure; the cleanups run in reverse order after the closure finishes, also when it throws, which matters because Revolt reuses callback fibers. With nothing registered the cost is one null check. A throwing hook is reported to the event loop error handler and never fails the async work. `FiberContext::forget(name)` removes a hook. Used by `webpatser/fledge-apm` to keep the active trace span across parallel fibers.
- Context only follows `async()`. `Future::iterate`, `ConcurrentClosureIterator`/`Pipeline::generate` and the server I/O loops queue their fibers directly and do not propagate it.

## v13.36.0.1 - 2026-10-13

### Fixed
- **`olap()` on the native drivers no longer leaks the OLAP workload into the pool.** `UsesNativePdoPool::olap()` now opens a lease scope, so the `set workload` statements and the callback's queries share one pooled PDO and that PDO goes back to the pool afterwards.
- **`setWorkload()` works with the native pools.** The stock version called `exec()` on the raw read slot, which holds a `NativePdoPool` rather than a PDO. The workload is now set on the fiber's leased write PDO and, when a read pool is configured, on its leased read PDO.

## v13.35.0.5 - 2026-10-08

### Fixed
- **Broadcasting and S3 integrations no longer recurse into the manager.** `BroadcastManager::extend()` and `FilesystemManager::extend()` rebind the driver closure's scope to the manager, so the `self::` calls inside the `pusher`/`reverb` and `s3` closures resolved to the manager's `__call()`. For broadcasting that meant `driver()` and the same closure again (stack overflow on the first `Broadcast::channel()`, killing every artisan command); for S3 it meant `disk()` and an `InvalidArgumentException`. The closures now capture the provider helpers as first-class callables and are `static`. New tests resolve the `pusher`, `reverb` and `s3` drivers and an SES transport through the real Laravel managers.

## v13.35.0.4 - 2026-10-08

### Added
- **`FledgeGuzzle`**: shared entry point (`stack()`, `client()`, `factory()`, `flush()`) for Guzzle consumers on the Fledge handler, with an optional per-host connection limit. Pools are shared per limit through `AsyncClientFactory`.
- **Third-party integrations on the Fledge HTTP client**, configured in `config/fledge-http.php` (merged by `FiberHttpServiceProvider`, scalars only, `config:cache` safe):
  - Mail: `FiberMailManager` runs HTTP transports (Postmark, Mailgun, Resend, ...) on the new `FledgeSymfonyHttpClient` and gives SES/SES v2 an AWS SDK `http_handler` on the Fledge stack. Per-mailer opt-out with `'client' => ['fledge' => false]`.
  - Broadcasting: the `pusher` and `reverb` drivers get `client_options.handler` on the Fledge stack.
  - S3: the `s3` filesystem driver gets an `http_handler` via the AWS SDK Guzzle bridge (no-op without `aws/aws-sdk-php`).
  - Elasticsearch: `ElasticClientFactory` and `FledgeElasticConnection` moved in from scrpr (`app/Support/Search`), registered as the `elasticsearch` database driver when `pdphilip/elasticsearch` is installed.
  - Per-host limits in `fledge-http.pool_per_host.{mail,broadcasting,s3,elasticsearch}` (defaults null, 8, null, 8).
- **`FledgeSymfonyHttpClient` and `FledgeSymfonyResponse`**: Symfony `HttpClientInterface` on the async client. `bindto`, `resolve`, `peer_fingerprint`, `ciphers`, `capture_peer_cert_chain` and `on_progress` throw `InvalidArgumentException`; `verify_host => false` on its own and `cafile` plus `capath` together throw as well; redirects are followed by the adapter and strip credential headers on cross-origin hops; unusable proxy settings fail lazily as `TransportException`.
- **fiberio hooks for the native drivers**: `fledge-http.fiberio_hooks` (env `FLEDGE_FIBERIO_HOOKS`: `sleep`, `dns`, `ssl`, `all`, `none`) selects the php-fiberio 0.2.0 hooks. Precedence: connection `fiberio_hooks`, then the config key, then `all`. Unknown names are ignored with one warning; if no valid name remains, no hooks are enabled. Process-global, decided by the first native connection. php-fiberio 0.1.0 still works (hooks argument not passed). Read through config, so it works under `config:cache`.
- `symfony/http-client`, `aws/aws-sdk-php`, `league/flysystem-aws-s3-v3`, `illuminate/broadcasting`, `pusher/pusher-php-server` and `pdphilip/elasticsearch` as dev dependencies so the integration tests run.

### Changed
- **Behaviour change: the third-party integrations are ON by default after upgrading.** Mail transports, Pusher/Reverb, S3 and Elasticsearch requests now go through the Fledge handler instead of curl, Symfony's client or the AWS default handler. Switch one off with `FLEDGE_HTTP_MAIL=false`, `FLEDGE_HTTP_BROADCASTING=false`, `FLEDGE_HTTP_S3=false` or `FLEDGE_HTTP_ELASTICSEARCH=false`, then run `php artisan config:cache` and restart workers. They stay inactive under PHPUnit.
- Elasticsearch: the Fledge handler is now also passed through `setHttpClientOptions()`. `ClientBuilder` rebuilds its Guzzle client when SSL verification is off or a CA bundle is set and used to drop the handler, falling back to curl.
- `AsyncClientFactory` takes an optional per-host connection limit.
- The native driver README now documents php-fiberio 0.2.0 (hooks for sleep, DNS and ssl://). With the `dns` hook, hostnames no longer block the process while connecting.

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
