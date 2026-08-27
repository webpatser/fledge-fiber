<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

/**
 * Reconnect backoff delays with phpredis-style algorithms. The default
 * instance reproduces the historic ReconnectingRedisLink behavior:
 * 0.05 * 2^min(n, 5) seconds, capped at 1.0.
 *
 * decorrelated_jitter is stateful across consecutive attempts; callers pass
 * the previous delay back in.
 */
final readonly class BackoffStrategy
{
    public function __construct(
        public string $algorithm = RetryPolicy::BACKOFF_DEFAULT,
        public float $base = 0.05,
        public float $cap = 1.0,
    ) {
        if (!\in_array($this->algorithm, RetryPolicy::BACKOFF_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                "Algorithm [{$this->algorithm}] is not a valid backoff algorithm."
            );
        }

        if ($this->base < 0.0 || $this->cap < 0.0) {
            throw new \InvalidArgumentException('Backoff base and cap must not be negative.');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Base precedence: backoff_base overrides retry_interval, which overrides
     * the historic 0.05s default; backoff_cap overrides the 1.0s cap.
     */
    public static function fromRetryPolicy(RetryPolicy $policy): self
    {
        return new self(
            algorithm: $policy->backoffAlgorithm,
            base: $policy->backoffBase ?? $policy->retryIntervalSeconds ?? 0.05,
            cap: $policy->backoffCap ?? 1.0,
        );
    }

    /**
     * Delay in seconds before reconnect attempt $attempt (1-based count of
     * consecutive failures). $previous carries the last returned delay for
     * the decorrelated_jitter algorithm.
     */
    public function delay(int $attempt, ?float $previous = null): float
    {
        $attempt = \max(1, $attempt);

        return match ($this->algorithm) {
            'default' => \min($this->base * (2 ** \min($attempt, 5)), $this->cap),
            'constant' => \min($this->base, $this->cap),
            'uniform' => self::randomBetween(0.0, \min($this->base, $this->cap)),
            'exponential' => $this->exponential($attempt),
            'full_jitter' => self::randomBetween(0.0, $this->exponential($attempt)),
            'equal_jitter' => $this->exponential($attempt) / 2
                + self::randomBetween(0.0, $this->exponential($attempt) / 2),
            'decorrelated_jitter' => \min(
                $this->cap,
                self::randomBetween($this->base, \max(($previous ?? $this->base) * 3, $this->base)),
            ),
            default => throw new \LogicException("Unhandled backoff algorithm [{$this->algorithm}]."),
        };
    }

    private function exponential(int $attempt): float
    {
        return \min($this->base * (2 ** \min($attempt, 30)), $this->cap);
    }

    private static function randomBetween(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + ($max - $min) * (\mt_rand() / \mt_getrandmax());
    }
}
