<?php declare(strict_types=1);

namespace Fledge\Async;

use Revolt\EventLoop;

/**
 * Propagates per-fiber context (for example an active tracing span) from the fiber calling async() into the
 * fiber that runs the closure.
 *
 * A hook registers a pair of closures under a name. `$capture()` runs in the parent fiber when async() is called
 * and returns a snapshot. `$restore($snapshot)` runs in the child fiber before the closure starts and may return
 * a cleanup closure, which runs after the closure finishes (also when it throws). Revolt reuses callback fibers,
 * so a hook that stores state per fiber must undo it in its cleanup.
 *
 * A throwing hook never breaks async(): the throwable is passed to the event loop error handler when one is set
 * (so a broken hook is visible, e.g. in the framework's exception reporter) and otherwise dropped. Context
 * propagation is observability plumbing; it must not fail the work it observes.
 */
final class FiberContext
{
    /** @var array<string, array{\Closure(): mixed, \Closure(mixed): (\Closure(): void)|null}> */
    private static array $hooks = [];

    /**
     * @param \Closure(): mixed $capture Runs in the parent fiber; returns the snapshot.
     * @param \Closure(mixed): ((\Closure(): void)|null) $restore Runs in the child fiber; returns an optional cleanup.
     */
    public static function register(string $name, \Closure $capture, \Closure $restore): void
    {
        self::$hooks[$name] = [$capture, $restore];
    }

    public static function forget(string $name): void
    {
        unset(self::$hooks[$name]);
    }

    /**
     * @internal Called by async() in the parent fiber.
     *
     * @return array<string, mixed>|null Snapshots keyed by hook name, or null when no hook is registered.
     */
    public static function capture(): ?array
    {
        if (self::$hooks === []) {
            return null;
        }

        $snapshots = [];

        foreach (self::$hooks as $name => [$capture]) {
            try {
                $snapshots[$name] = $capture();
            } catch (\Throwable $exception) {
                self::report($exception);
            }
        }

        return $snapshots;
    }

    /**
     * @internal Called by async() in the child fiber before the closure runs.
     *
     * @param array<string, mixed> $snapshots
     *
     * @return list<\Closure(): void> Cleanups in restore order.
     */
    public static function restore(array $snapshots): array
    {
        $cleanups = [];

        foreach ($snapshots as $name => $snapshot) {
            // A hook forgotten between capture and restore is skipped.
            $restore = self::$hooks[$name][1] ?? null;

            if ($restore === null) {
                continue;
            }

            try {
                $cleanup = $restore($snapshot);
            } catch (\Throwable $exception) {
                self::report($exception);

                continue;
            }

            if ($cleanup instanceof \Closure) {
                $cleanups[] = $cleanup;
            }
        }

        return $cleanups;
    }

    /**
     * @internal Called by async() in the child fiber after the closure finished.
     *
     * @param list<\Closure(): void> $cleanups
     */
    public static function cleanup(array $cleanups): void
    {
        foreach (\array_reverse($cleanups) as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable $exception) {
                self::report($exception);
            }
        }
    }

    private static function report(\Throwable $exception): void
    {
        $handler = EventLoop::getErrorHandler();

        if ($handler === null) {
            return;
        }

        try {
            $handler($exception);
        } catch (\Throwable) {
            // The error handler itself failed; nothing left to report to.
        }
    }
}
