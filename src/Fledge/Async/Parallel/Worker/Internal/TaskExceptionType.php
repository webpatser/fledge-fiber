<?php declare(strict_types=1);

namespace Fledge\Async\Parallel\Worker\Internal;

use Fledge\Async\Parallel\Worker\TaskFailureError;
use Fledge\Async\Parallel\Worker\TaskFailureException;
use Fledge\Async\Parallel\Worker\TaskFailureThrowable;

/**
 * @psalm-import-type FlattenedTrace from TaskFailureThrowable
 * @internal
 */
enum TaskExceptionType
{
    case Exception;
    case Error;

    public static function fromException(\Throwable $exception): self
    {
        return $exception instanceof \Error
            ? self::Error
            : self::Exception;
    }

    /**
     * @param class-string<\Throwable> $className
     * @param FlattenedTrace $trace
     */
    public function createException(
        string $className,
        string $message,
        int|string $code,
        string $file,
        int $line,
        array $trace,
        ?\Throwable $previous = null,
    ): TaskFailureThrowable {
        return match ($this) {
            self::Exception => new TaskFailureException(
                $className,
                $message,
                $code,
                $file,
                $line,
                $trace,
                $previous,
            ),
            self::Error => new TaskFailureError(
                $className,
                $message,
                $code,
                $file,
                $line,
                $trace,
                $previous,
            ),
        };
    }
}
