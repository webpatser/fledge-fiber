# Fledge Fiber

Non-blocking async drivers for the [Fledge framework](https://github.com/webpatser/fledge) — PHP 8.5 only.

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
