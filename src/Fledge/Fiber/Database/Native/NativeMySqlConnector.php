<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Connector for the native driver: stock pdo_mysql made fiber-aware by the
 * php-fiberio extension, one real PDO per leased connection.
 *
 * connect() never opens a connection. It enables fiberio, validates the
 * config and returns a NativePdoPool whose factory builds each physical PDO
 * through Laravel's own MySqlConnector (DSN, SSL attrs, sql_mode, isolation
 * level, timezone and init command all stay stock behaviour).
 */
class NativeMySqlConnector implements ConnectorInterface
{
    /**
     * Whether the optional-fiberio warning was already logged in this process.
     */
    private static bool $warnedFiberIoMissing = false;

    /**
     * Whether the unknown-hook-names warning was already logged in this process.
     */
    private static bool $warnedUnknownHooks = false;

    /**
     * @throws InvalidArgumentException when PDO::ATTR_PERSISTENT is set
     * @throws RuntimeException when fiberio is required but unavailable
     */
    public function connect(array $config): NativePdoPool
    {
        $this->rejectPersistent($config);
        $this->ensureFiberIo($config);

        return $this->createPool($config);
    }

    /**
     * The connector that builds each physical PDO: stock MySqlConnector,
     * producing NativePdo instances (lease-owner guarded).
     */
    protected function stockConnector(): ConnectorInterface
    {
        return new class extends MySqlConnector
        {
            use CreatesNativePdo;
        };
    }

    protected function createPool(array $config): NativePdoPool
    {
        $stock = $this->stockConnector();

        return new NativePdoPool(
            fn () => $stock->connect($config),
            (int) ($config['pool_size'] ?? 32),
            (float) ($config['pool_idle_timeout'] ?? 60),
            waitTimeout: (float) ($config['pool_wait_timeout'] ?? 30),
        );
    }

    /**
     * fiberio routes persistent streams to the blocking stock transport, so a
     * persistent connection would silently stall the event loop.
     */
    protected function rejectPersistent(array $config): void
    {
        if (($config['options'][PDO::ATTR_PERSISTENT] ?? false)) {
            throw new InvalidArgumentException(
                'The native MySQL/MariaDB driver does not support PDO::ATTR_PERSISTENT: fiberio routes persistent '
                .'streams to the blocking transport. Remove the option; the driver pools connections itself.'
            );
        }
    }

    /**
     * Whether the loaded php-fiberio accepts the hooks argument (>= 0.2).
     */
    public static function fiberIoSupportsHooks(): bool
    {
        return defined('FiberIo\\HOOK_ALL');
    }

    /**
     * Build the fiberio hooks bitmask.
     *
     * Precedence: the connection config key `fiberio_hooks`, then the app
     * config `fledge-http.fiberio_hooks` (env FLEDGE_FIBERIO_HOOKS in the
     * shipped config file, so it survives config:cache), then `all`.
     *
     * Accepts a comma list or array of sleep, dns, ssl, or `all`. `none`,
     * `0`, `false`, `off`, `no` and the empty string mean no hooks. Unknown
     * names are ignored with one logged warning per process; when no valid
     * name remains the result is no hooks (0), never all. Only call when
     * fiberIoSupportsHooks().
     *
     * @param  array<string, mixed>  $config
     */
    public static function resolveHooks(array $config): int
    {
        $value = $config['fiberio_hooks'] ?? self::appHooksSetting() ?? 'all';

        if (is_bool($value) || is_int($value)) {
            $value = $value ? 'all' : 'none';
        }

        $names = is_array($value) ? $value : explode(',', (string) $value);
        $map = [
            'sleep' => \FiberIo\HOOK_SLEEP,
            'dns' => \FiberIo\HOOK_DNS,
            'ssl' => \FiberIo\HOOK_SSL,
        ];
        $none = ['', 'none', '0', 'false', 'off', 'no'];

        $mask = 0;
        $unknown = [];

        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));

            if ($name === 'all') {
                return \FiberIo\HOOK_ALL;
            }

            if (isset($map[$name])) {
                $mask |= $map[$name];
            } elseif (! in_array($name, $none, true)) {
                $unknown[] = $name;
            }
        }

        if ($unknown !== []) {
            self::warnUnknownHooks($unknown);
        }

        return $mask;
    }

    /**
     * The app-wide `fledge-http.fiberio_hooks` setting, or null when no
     * container or config repository is available.
     */
    private static function appHooksSetting(): mixed
    {
        try {
            $container = Container::getInstance();

            return $container->bound('config') ? $container->make('config')->get('fledge-http.fiberio_hooks') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $unknown
     */
    private static function warnUnknownHooks(array $unknown): void
    {
        if (self::$warnedUnknownHooks) {
            return;
        }

        self::$warnedUnknownHooks = true;

        try {
            $container = Container::getInstance();

            if ($container->bound('log')) {
                $container->make('log')->warning(
                    'Ignoring unknown fiberio hook name(s): '.implode(', ', $unknown)
                    .'. Valid names: sleep, dns, ssl, all, none.'
                );
            }
        } catch (\Throwable) {
            // No usable logger: the unknown names are still ignored.
        }
    }

    /**
     * Make sure fiberio is loaded and enabled with the Revolt waiter.
     *
     * With `'fiberio' => 'optional'` a missing extension logs one warning per
     * process and continues with blocking PDO.
     *
     * fiberio state is process-global: enable() runs once, so the first
     * native connection to connect decides the hooks for the whole process.
     * A different `fiberio_hooks` on a later connection has no effect; set
     * it app-wide through `fledge-http.fiberio_hooks` instead.
     *
     * @throws RuntimeException
     */
    protected function ensureFiberIo(array $config): void
    {
        if (! extension_loaded('fiberio')) {
            if (($config['fiberio'] ?? null) === 'optional') {
                $this->warnFiberIoMissing();

                return;
            }

            throw new RuntimeException(
                'The native MySQL/MariaDB driver requires the fiberio extension. '
                .'Install it with: pie install webpatser/php-fiberio '
                ."(or set 'fiberio' => 'optional' on the connection to fall back to blocking PDO)."
            );
        }

        if (PHP_ZTS) {
            throw $this->nonThreadSafeOnly();
        }

        try {
            if (! \FiberIo\enabled()) {
                if (self::fiberIoSupportsHooks()) {
                    \FiberIo\enable(new RevoltWaiter, self::resolveHooks($config));
                } else {
                    // php-fiberio v0.1.0: no hooks argument.
                    \FiberIo\enable(new RevoltWaiter);
                }
            }
        } catch (\Error $e) {
            throw $this->nonThreadSafeOnly($e);
        }
    }

    protected function warnFiberIoMissing(): void
    {
        if (self::$warnedFiberIoMissing) {
            return;
        }

        self::$warnedFiberIoMissing = true;

        Log::warning(
            'fiberio extension not loaded: the native MySQL/MariaDB driver falls back to blocking PDO, '
            .'so queries will not yield to the event loop. Install it with: pie install webpatser/php-fiberio'
        );
    }

    private function nonThreadSafeOnly(?\Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(
            'fiberio could not be enabled. The extension supports non-thread-safe (NTS) PHP builds only; '
            .'use an NTS PHP binary for the native driver.',
            0,
            $previous,
        );
    }
}
