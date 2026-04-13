<?php declare(strict_types=1);

namespace Fledge\Async\Internal;

/**
 * Check if debug mode is enabled via the FLEDGE_DEBUG environment variable.
 *
 * @return bool
 *
 * @internal
 */
function isDebugEnabled(): bool
{
    static $enabled;

    return $enabled ??= (bool) \getenv('FLEDGE_DEBUG');
}

/**
 * Format a debug backtrace into a readable string.
 *
 * @param list<array{file?: string, line?: int, class?: string, type?: string, function?: string}> $trace
 *
 * @return string
 *
 * @internal
 */
function formatStacktrace(array $trace): string
{
    return \implode("\n", \array_map(static function (array $frame, int $index): string {
        $prefix = "#$index ";

        if (isset($frame['file'])) {
            $prefix .= $frame['file'] . ':' . ($frame['line'] ?? '?') . ' ';
        }

        if (isset($frame['class'])) {
            return $prefix . $frame['class'] . ($frame['type'] ?? '->') . $frame['function'] . '()';
        }

        if (isset($frame['function'])) {
            return $prefix . $frame['function'] . '()';
        }

        return $prefix . '{main}';
    }, $trace, \array_keys($trace)));
}
