<?php

declare(strict_types=1);

namespace Fledge\Fiber\Database\Native;

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
     * Make sure fiberio is loaded and enabled with the Revolt waiter.
     *
     * With `'fiberio' => 'optional'` a missing extension logs one warning per
     * process and continues with blocking PDO.
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
                \FiberIo\enable(new RevoltWaiter);
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
