# Origins

Fledge's async layer was ported from the amphp packages on 2026-04-10. amphp is MIT licensed (the original copyright notices are in `LICENSE`), and the code has been maintained independently since; Fledge does not follow amphp releases.

| Module | Derived from (package) | Based on version | License |
|---|---|---|---|
| `Fledge\Async` core (Future, cancellations, Interval) | [amphp/amp](https://github.com/amphp/amp) | v3.1.3 | MIT |
| `Fledge\Async` (Pipeline, Queue, ConcurrentIterator) | [amphp/pipeline](https://github.com/amphp/pipeline) | v1.2.7 | MIT |
| `Fledge\Async\Stream` (streams) | [amphp/byte-stream](https://github.com/amphp/byte-stream) | v2.1.2 | MIT |
| `Fledge\Async\Stream` (sockets, TLS) | [amphp/socket](https://github.com/amphp/socket) | v2.3.1 | MIT |
| `Fledge\Async\Stream\Parser` | [amphp/parser](https://github.com/amphp/parser) | v1.1.1 | MIT |
| `Fledge\Async\Cache` | [amphp/cache](https://github.com/amphp/cache) | v2.0.1 | MIT |
| `Fledge\Async\Sync` | [amphp/sync](https://github.com/amphp/sync) | v2.3.0 | MIT |
| `Fledge\Async\Dns` | [amphp/dns](https://github.com/amphp/dns) | v2.4.0 | MIT |
| `Fledge\Async\File` | [amphp/file](https://github.com/amphp/file) | v3.2.0 | MIT |
| `Fledge\Async\Process` | [amphp/process](https://github.com/amphp/process) | v2.0.3 | MIT |
| `Fledge\Async\Parallel` | [amphp/parallel](https://github.com/amphp/parallel) | v2.3.4 (plus one fix from v2.4.0) | MIT |
| `Fledge\Async\Serialization` | [amphp/serialization](https://github.com/amphp/serialization) | v1.1.0 | MIT |
| `Fledge\Async\Database` (Sql interfaces) | [amphp/sql](https://github.com/amphp/sql) | v2.1.1 | MIT |
| `Fledge\Async\Database` (pooled/common) | [amphp/sql-common](https://github.com/amphp/sql-common) | v2.0.4 | MIT |
| `Fledge\Async\Database\Mysql` | [amphp/mysql](https://github.com/amphp/mysql) | v3.1.1 | MIT |
| `Fledge\Async\Database\Postgres` | [amphp/postgres](https://github.com/amphp/postgres) | v2.2.1 | MIT |
| `Fledge\Async\Redis` | [amphp/redis](https://github.com/amphp/redis) | v2.0.4 | MIT |
| `Fledge\Async\Http` (primitives, cookies, HPack) | [amphp/http](https://github.com/amphp/http) | v2.1.2 (plus the hpack v3.2.2 `chr()` fix) | MIT |
| `Fledge\Async\Http\Client` | [amphp/http-client](https://github.com/amphp/http-client) | v5.3.6 | MIT |
| `Fledge\Async\Http\Server` | [amphp/http-server](https://github.com/amphp/http-server) | v3.4.6 | MIT |
| `Fledge\Async\WebSocket` | [amphp/websocket](https://github.com/amphp/websocket) | v2.0.4 | MIT |
| `Fledge\Async\WebSocket\Client` | [amphp/websocket-client](https://github.com/amphp/websocket-client) | v2.0.2 | MIT |
| `Fledge\Async\WebSocket\Server` | [amphp/websocket-server](https://github.com/amphp/websocket-server) | v4.0.0 | MIT |

Event loop: see [webpatser/fledge-loop](https://github.com/webpatser/fledge-loop), derived from revolt/event-loop v1.1.0 (see its own ORIGINS.md).

## Security fixes present since the import

- CVE-2025-8671 "MadeYouReset" HTTP/2 DoS, fixed in amphp/http-server v3.4.4: the `RESET_STREAM_RATIO` sliding-window reset protection in `Http/Server/Driver/Http2Driver.php`.
- HTTP/2 ping flood mitigation, from amphp/http-server v3.4.5: a ping counter with `ENHANCE_YOUR_CALM` in `Http/Server/Driver/Http2Driver.php`.

## Notable differences from the originals

- **Sockets and TLS**: the independent `verifyPeerName` API is removed from both TLS contexts; `verify_peer_name` always follows `verifyPeer` (fail-safe). The URI handling uses PHP's native `Uri` extension instead of league/uri.
- **MySQL statement creation**: the shared `Internal/MysqlStatementCreationDelegate` trait is not used; the equivalent `createStatement()` is inlined in `MysqlTransactionDelegate` and `MysqlConnectionPool`.
- **Debug flag**: the `AMP_DEBUG` detection is replaced by a `FLEDGE_DEBUG` environment flag without a constant fallback.
- **PHP version**: the `PHP_VERSION_ID` guards (for `str_increment` and similar) are dropped; Fledge supports only the latest stable PHP.
- **Postgres `PostgresConfig`** is extended for Laravel parity: `sslCert`/`sslKey`/`sslRootCert` constructor properties with KEY_MAP entries, getters and withers, and quoted `sslcert=`/`sslkey=`/`sslrootcert=` connection-string chunks. The `host=` chunk is omitted when the host is empty so libpq defaults (unix socket or localhost) apply.
- **MySQL handshake charset** is 45 (`utf8mb4_general_ci`) rather than 255 (`utf8mb4_0900_ai_ci`), which is MySQL 8 only and breaks MariaDB and MySQL 5.7 handshakes. The `MysqlConfig` constructor and `fromString()` collation default is `''` (the constant is kept for explicit use).
- **MySQL `ConnectionProcessor`** always issues `SET NAMES` when a charset is configured (a COLLATE clause only when a collation is set), instead of skipping it when the values match the defaults, which left sessions on the handshake collation.
- **MySQL TLS**: `ConnectionProcessor::sendHandshake()` requires CLIENT_SSL when TLS is configured; a greeting without it fails with `SqlConnectionException` instead of silently continuing in plaintext (STARTTLS downgrade).
- **Redis** is extended for Laravel parity: a structured `RedisConfig::fromParameters()` constructor (TLS context options, ACL username, read timeout, retry policy, client name, tcp keepalive), `CommandKeySpec` extracted from the cluster key extractor for phpredis-style key prefixing, and a `ReconnectingRedisLink` with a read timeout, an idempotent resend whitelist and configurable backoff.
- **Redis `RespParser::parseArray()`** keeps nested errors as values: an error inside an array reply (one failing command in EXEC) is stored as a `QueryException` in that position (also in `Resp3ExtensionParser`) instead of throwing inside the parser and tearing down the shared connection. Only top-level error replies throw.
- **HTTP client**: the Laravel handler bypasses the default `HttpClientBuilder` pipeline. The Guzzle bridge builds clients with redirects, transport retries and (optionally) decompression disabled so Guzzle middleware owns those semantics, and an `HttpConnectSocketConnector` (CONNECT proxy) sits next to the SOCKS5 connector.
- **SQL statement pool**: `SqlStatementPool` closes the statement on the `push()` decline paths and the release closure drops its reference, which fixes a prepare deadlock on a saturated pool.
- **Postgres DEALLOCATE** is sent without result processing and tolerates failure on both backends, so the error drain cannot consume other operations' responses after a DISCARD ALL reset.
- **HTTP server `Request::addHeader()`** parses only the added cookie lines instead of re-parsing every `cookie` header on each add, which was quadratic when the HTTP/1 driver adds thousands of `Cookie:` lines one by one (an event-loop stall). Cookie detection in `setHeader`/`addHeader`/`removeHeader` is an exact `cookie` match instead of a `stripos` prefix.

amphp and Revolt remain a source of inspiration, and fixes from either are judged case by case.
