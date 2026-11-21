<?php

declare(strict_types=1);

namespace Fledge\Async\EventLoop;

use Fledge\Async\EventLoop\Driver\PollDriver;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\DriverFactory;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * Selects and installs the Revolt event loop driver a Fledge process runs on.
 *
 * The name comes from the argument, or from the `FLEDGE_EVENT_LOOP` environment variable when the argument is
 * null (default `auto`). Matching ignores case and surrounding whitespace; an empty value means `auto`.
 *
 * - `poll`: {@see PollDriver} on PHP 8.6's `Io\Poll` (kqueue, epoll or poll). Throws when no backend is
 *   available; an explicit `poll` never falls back silently.
 * - `select`: Revolt's {@see StreamSelectDriver}, always, even when ext-uv, ext-ev or ext-event is loaded.
 *   This is the rollback value.
 * - `revolt`: whatever Revolt's own {@see DriverFactory} creates (honours `REVOLT_DRIVER`).
 * - `auto`: the Fledge default: `poll` when {@see PollDriver::isSupported()}, otherwise `revolt`. Never throws
 *   for lack of a backend; see {@see self::auto()}.
 *
 * Fork rule: kqueue and epoll descriptors do not survive `fork()`. Call {@see self::install()} in the worker
 * process after it has forked, never in a master process that forks workers afterwards. For the same reason
 * {@see self::install()} always creates a fresh driver, even when the active one is already of the requested kind:
 * an inherited driver may hold a descriptor that belongs to the parent.
 */
final class LoopDriver
{
    public const string ENV = 'FLEDGE_EVENT_LOOP';

    private const array NAMES = ['auto', 'poll', 'select', 'revolt'];

    /**
     * The normalised effective driver name: `poll`, `select` or `revolt`, with `auto` already resolved (to `poll`
     * when {@see PollDriver::isSupported()}, else `revolt`).
     *
     * @throws \InvalidArgumentException When the name is not one of the accepted values.
     */
    public static function resolve(?string $name = null): string
    {
        $name ??= self::fromEnvironment();
        $normalised = \strtolower(\trim($name));

        if ($normalised === '') {
            $normalised = 'auto';
        }

        if (!\in_array($normalised, self::NAMES, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown event loop driver "%s" (%s); expected one of: %s.',
                $name,
                self::ENV,
                \implode(', ', self::NAMES),
            ));
        }

        return $normalised === 'auto' ? self::auto() : $normalised;
    }

    /**
     * What `auto` resolves to, the single place the default is decided. The poll driver won the benchmark gate
     * on 2026-11-21 (see bench/eventloop/README.md), so `auto` is `poll` where `Io\Poll` has a backend and
     * Revolt's own factory choice otherwise.
     */
    private static function auto(): string
    {
        return PollDriver::isSupported() ? 'poll' : 'revolt';
    }

    /**
     * Creates a new, uninstalled driver.
     *
     * @throws \InvalidArgumentException When the name is not one of the accepted values.
     * @throws UnsupportedFeatureException When `poll` is requested but no `Io\Poll` backend is available.
     */
    public static function create(?string $name = null): Driver
    {
        return match (self::resolve($name)) {
            'poll' => PollDriver::isSupported()
                ? new PollDriver()
                : throw new UnsupportedFeatureException(\sprintf(
                    'Event loop driver "poll" was requested but no Io\Poll backend is available; set %s=select to roll back.',
                    self::ENV,
                )),
            'select' => new StreamSelectDriver(),
            'revolt' => (new DriverFactory())->create(),
        };
    }

    /**
     * Creates a driver and makes it the active loop driver.
     *
     * Refuses while the active loop is running, or while it has any callback registered (enabled or disabled,
     * referenced or not), since swapping would silently drop that work. Nothing is changed when it refuses.
     *
     * @throws \LogicException When the active loop is running or has callbacks registered.
     * @throws \InvalidArgumentException When the name is not one of the accepted values.
     * @throws UnsupportedFeatureException When `poll` is requested but no `Io\Poll` backend is available.
     */
    public static function install(?string $name = null): Driver
    {
        $resolved = self::resolve($name);
        $current = EventLoop::getDriver();

        if ($current->isRunning()) {
            throw new \LogicException(\sprintf(
                'Cannot install the "%s" event loop driver while the event loop is running; install it before the loop starts.',
                $resolved,
            ));
        }

        $registered = \count($current->getIdentifiers());

        if ($registered > 0) {
            throw new \LogicException(\sprintf(
                'Cannot install the "%s" event loop driver: the active %s driver still has %d callback(s) registered.',
                $resolved,
                $current::class,
                $registered,
            ));
        }

        $driver = self::create($resolved);
        EventLoop::setDriver($driver);

        return $driver;
    }

    private static function fromEnvironment(): string
    {
        $value = \getenv(self::ENV);

        return \is_string($value) ? $value : 'auto';
    }
}
