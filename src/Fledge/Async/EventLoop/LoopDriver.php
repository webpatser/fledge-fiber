<?php

declare(strict_types=1);

namespace Fledge\Async\EventLoop;

use Fledge\Async\EventLoop\Driver\PollDriver;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\EventLoop\DriverFactory;
use Revolt\EventLoop\Internal\AbstractDriver;
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
 * Which entry point:
 *
 * - {@see self::install()}: a process's own first setup, before its loop has run anything. It is strict: it
 *   refuses while the active loop is running or has callbacks registered, and always creates a fresh driver.
 * - {@see self::afterFork()}: the first call in a child right after `pcntl_fork()` returned 0, when the parent had
 *   already used the loop (a queue master that forks workers, a server that forks). It discards the inherited driver
 *   whatever its state and installs a fresh one. The child starts with an EMPTY loop: every timer, stream watcher,
 *   deferred callback and loop signal handler of the parent is gone, and so is anything that depends on them
 *   (servers, timeout queues, pools created in the parent). Create those again in the child. Callback ids the
 *   parent handed out are dead in the child: the fresh driver continues the parent's id sequence, so such an id is
 *   unknown rather than an alias for one of the child's callbacks.
 *
 * `afterFork()` is the only full protection for a forked child. Without it the poll driver throws when the child
 * syncs a watcher or waits on the inherited Context, and releases the Context in a shutdown function so that the
 * child's exit does not delete the parent's registrations; that shutdown hook is best effort, because a shutdown
 * function registered before the driver was created runs first and may close a stream the parent watches while the
 * inherited Context is still alive.
 *
 * Fork from a quiet point, with no fiber suspended mid-command on a connection. Fibers suspended in the parent unwind
 * in the child (during `afterFork()`, or at the child's exit without it), and their cleanup can write to the
 * parent's peer: that is a property of forking with live fibers, not of `afterFork()`.
 *
 * Why the child cannot keep the parent's driver: an epoll descriptor is shared with the parent after `fork()`
 * (changes in the child alter the parent's registrations; the parent segfaults on the child's events), and a kqueue
 * descriptor is not inherited at all. {@see PollDriver} refuses to be used in a process that did not create it.
 */
final class LoopDriver
{
    public const string ENV = 'FLEDGE_EVENT_LOOP';

    private const array NAMES = ['auto', 'poll', 'select', 'revolt'];

    /** @var \WeakMap<Driver, int>|null Process that installed each driver through this class. */
    private static ?\WeakMap $installedBy = null;

    private static bool $warnedIdFallback = false;

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
     * referenced or not), since swapping would silently drop that work. Nothing is changed when it refuses. In a
     * child forked from a process that used the loop, call {@see self::afterFork()} instead; the refusal says so.
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
                'Cannot install the "%s" event loop driver while the event loop is running; install it before the loop starts.%s',
                $resolved,
                self::forkHint($current),
            ));
        }

        $registered = \count($current->getIdentifiers());

        if ($registered > 0) {
            throw new \LogicException(\sprintf(
                'Cannot install the "%s" event loop driver: the active %s driver still has %d callback(s) registered.%s',
                $resolved,
                $current::class,
                $registered,
                self::forkHint($current),
            ));
        }

        $driver = self::create($resolved);
        EventLoop::setDriver($driver);
        self::remember($driver);

        return $driver;
    }

    /**
     * Discards the driver inherited through `fork()` and installs a fresh one, chosen like {@see self::install()}.
     *
     * Call it in the child as the first thing after `pcntl_fork()` returned 0, from {main} (not inside a fiber or an
     * event loop callback), and before the child opens or closes any descriptor. It works whatever state Revolt
     * reports for the inherited driver (running because the parent awaited from {main}, or with callbacks
     * registered), for every driver kind on either side.
     *
     * What it does, in this order:
     *
     * 1. A {@see PollDriver} releases its `Io\Poll\Context` without any kernel operation. On Linux that closes the
     *    child's copy of the epoll descriptor, leaving the parent's registrations intact; only then is it safe for
     *    the child to close inherited streams (while the inherited Context lives, closing a stream it watches deletes
     *    the parent's registration). On macOS and BSD the kqueue descriptor was never inherited and its number may
     *    already be reused, so the Context is parked until exit instead of being closed.
     * 2. Every callback of an inherited {@see PollDriver} or {@see StreamSelectDriver} is cancelled. Signal handlers
     *    the parent registered through the loop are reset to `SIG_DFL` in the child, so a signal no longer lands in
     *    the dead loop; register the child's own with `EventLoop::onSignal()` afterwards. Dispositions set directly
     *    with `pcntl_signal()` (including `SIG_IGN`) are inherited by the OS and left untouched. Other drivers (ev, uv,
     *    event) are dropped as they are and rely on their extension's own fork handling.
     * 3. The new driver continues the inherited driver's callback id sequence (Revolt's private
     *    `AbstractDriver::$nextId`, carried by reflection right after the install in step 4 and before this method
     *    returns; ev, uv, event and select drivers all extend AbstractDriver, and a TracingDriver is unwrapped on
     *    either side; if the reflection fails, the ids are reserved through the public API with one E_USER_WARNING,
     *    see continueCallbackIds()), so a parent-created id held by a parent-built object is
     *    unknown in the child instead of naming one of the child's callbacks. Revolt treats an unknown id as a no-op
     *    in `cancel()`, `disable()` and `unreference()`, and throws `InvalidCallbackError` from `enable()`,
     *    `reference()`, `isEnabled()`, `isReferenced()` and `getType()`.
     * 4. The new driver replaces Revolt's private static `EventLoop::$driver` (the one piece of global state: the
     *    loop fiber, the callback fiber and the suspensions all live inside the driver object). Revolt's own
     *    `setDriver()` refuses while the inherited driver counts as running, so the property is set by reflection,
     *    after which `EventLoop::setDriver()` performs its usual swap with a placeholder driver installed while it
     *    garbage-collects the old driver.
     *
     * The inherited loop fiber is never resumed in the child. Fibers that were suspended in the parent are destroyed
     * when the old driver is collected, which in PHP means they unwind: their `finally` blocks and destructors run in
     * the child during this call, against the placeholder driver (cancelling a callback is a no-op there). Closing
     * an inherited socket at that point is safe for the parent, but anything that writes to an inherited connection
     * would reach the parent's peer, so fork from a point where no fiber owns a connection mid-exchange. The same
     * unwinding happens at the child's exit when afterFork() is not called: it is a property of forking with live
     * fibers, not of this method.
     *
     * @throws \LogicException When called inside a fiber or event loop callback; or, after the new driver was
     *     installed, when something still references the released epoll Context (for example a value obtained from
     *     `getHandle()`), because closing an inherited stream would then still delete the parent's registration.
     * @throws \InvalidArgumentException When the name is not one of the accepted values (nothing is changed).
     * @throws UnsupportedFeatureException When `poll` is requested but no `Io\Poll` backend is available.
     */
    public static function afterFork(?string $name = null): Driver
    {
        if (\Fiber::getCurrent() !== null) {
            throw new \LogicException(
                'LoopDriver::afterFork() must be called from {main}, not inside a fiber or an event loop callback; '
                .'call it in the child right after pcntl_fork() returned 0.',
            );
        }

        $driver = self::create($name); // Throws before anything is discarded.
        $property = new \ReflectionProperty(EventLoop::class, 'driver');
        $inherited = $property->isInitialized() ? $property->getValue() : null;
        $released = null;

        if ($inherited instanceof PollDriver) {
            $released = $inherited->releaseAfterFork();
        }

        if ($inherited instanceof PollDriver || $inherited instanceof StreamSelectDriver) {
            foreach ($inherited->getIdentifiers() as $callbackId) {
                $inherited->cancel($callbackId);
            }
        }

        $nextCallbackId = self::nextCallbackId($inherited);
        unset($inherited);

        $property->setValue(null, $driver);
        EventLoop::setDriver($driver);
        self::remember($driver);

        // After the install, so a failure here cannot leave the child without a driver, and before this method
        // returns, so no child code has registered a callback on the new driver yet. Never throws.
        self::continueCallbackIds($driver, $nextCallbackId);

        if ($released?->get() !== null) {
            throw new \LogicException(
                'LoopDriver::afterFork() installed a fresh driver, but the inherited Io\\Poll\\Context is still referenced '
                .'(for example through PollDriver::getHandle()), so the epoll descriptor shared with the parent stays open '
                .'in this process; drop that reference before closing any inherited stream.',
            );
        }

        return $driver;
    }

    /**
     * The id the inherited driver would hand out next, or null when it is unknown. Reads Revolt's private
     * `AbstractDriver::$nextId`; if that fails (a future Revolt release renamed or retyped it), asks the driver
     * itself by registering and cancelling one deferred callback, which touches no kernel object. Never throws.
     */
    private static function nextCallbackId(?Driver $inherited): ?string
    {
        if ($inherited === null) {
            return null;
        }

        try {
            $from = self::abstractDriver($inherited);
            $nextId = $from === null ? null : (new \ReflectionProperty(AbstractDriver::class, 'nextId'))->getValue($from);

            if (\is_string($nextId)) {
                return $nextId;
            }
        } catch (\Throwable) {
            // Fall through to the public API.
        }

        try {
            $callbackId = $inherited->defer(static function (): void {});
            $inherited->cancel($callbackId);

            return $callbackId;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Starts the new driver's callback ids at or after the inherited driver's next id, so a stale parent id is
     * unknown in the child instead of naming one of the child's callbacks. Never throws.
     *
     * Normally it writes Revolt's private `AbstractDriver::$nextId`. If that reflection fails, it falls back to
     * {@see self::reserveCallbackIds()} (public API only, one deferred callback registered and cancelled per id the
     * parent used, so linear in the parent's id count) and raises one E_USER_WARNING per process naming the
     * revolt/event-loop version, because the fallback can be slow for a long-lived parent and the reflection should
     * be fixed. Ids are never silently reused.
     */
    private static function continueCallbackIds(Driver $driver, ?string $nextCallbackId): void
    {
        if ($nextCallbackId === null) {
            return;
        }

        try {
            $to = self::abstractDriver($driver);

            if ($to === null) {
                return; // Not Revolt's id scheme; nothing to align.
            }

            (new \ReflectionProperty(AbstractDriver::class, 'nextId'))->setValue($to, $nextCallbackId);

            return;
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $reserved = self::reserveCallbackIds($driver, $nextCallbackId);

        if (!self::$warnedIdFallback) {
            self::$warnedIdFallback = true;
            \trigger_error(\sprintf(
                'LoopDriver::afterFork() could not carry the callback id counter into the new driver by reflection '
                .'(revolt/event-loop %s: %s); it reserved the parent\'s ids by registering and cancelling %d '
                .'callback(s) instead. Update Fledge for this Revolt version.',
                self::revoltVersion(),
                $failure->getMessage(),
                $reserved,
            ), \E_USER_WARNING);
        }
    }

    /**
     * Advances a fresh driver's ids past $nextCallbackId through the public API: registers and cancels deferred
     * callbacks until the driver hands out that id. Returns how many it registered. Stops early (without reaching the
     * id) only if the driver's ids grow longer than the target, which Revolt's sequence never does before reaching it.
     */
    private static function reserveCallbackIds(Driver $driver, string $nextCallbackId): int
    {
        $count = 0;

        try {
            do {
                $callbackId = $driver->defer(static function (): void {});
                $driver->cancel($callbackId);
                $count++;
            } while ($callbackId !== $nextCallbackId && \strlen($callbackId) <= \strlen($nextCallbackId));
        } catch (\Throwable) {
            // Best effort; the warning tells the operator.
        }

        return $count;
    }

    private static function revoltVersion(): string
    {
        try {
            return \class_exists(\Composer\InstalledVersions::class)
                ? (string) \Composer\InstalledVersions::getPrettyVersion('revolt/event-loop')
                : 'unknown version';
        } catch (\Throwable) {
            return 'unknown version';
        }
    }

    private static function abstractDriver(Driver $driver): ?AbstractDriver
    {
        while ($driver instanceof TracingDriver) {
            $driver = (new \ReflectionProperty(TracingDriver::class, 'driver'))->getValue($driver);
        }

        return $driver instanceof AbstractDriver ? $driver : null;
    }

    private static function remember(Driver $driver): void
    {
        self::$installedBy ??= new \WeakMap();
        self::$installedBy[$driver] = \getmypid();
    }

    private static function forkHint(Driver $current): string
    {
        $pid = self::$installedBy[$current] ?? null;

        if ($pid !== null && $pid !== \getmypid()) {
            return \sprintf(
                ' The active driver was installed in process %d and inherited through fork(): call %s::afterFork() in the child instead.',
                $pid,
                self::class,
            );
        }

        return \sprintf(' In a child forked from a process that used the event loop, call %s::afterFork() instead.', self::class);
    }

    private static function fromEnvironment(): string
    {
        $value = \getenv(self::ENV);

        return \is_string($value) ? $value : 'auto';
    }
}
