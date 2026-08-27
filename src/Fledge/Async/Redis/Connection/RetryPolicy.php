<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Connection;

/**
 * Value class carrying the retry related connection options from a Laravel
 * redis configuration array, mirroring the phpredis option semantics:
 *
 * - command_retries: how often a failed retryable command is retried at the
 *   connection level (phpredis Redis::OPT_MAX_RETRIES applied per command).
 * - maxRetries: bounds consecutive reconnect attempts of the underlying link.
 * - retryIntervalSeconds: base delay between reconnect attempts (phpredis
 *   retry_interval, configured in milliseconds).
 * - backoffAlgorithm/backoffBase/backoffCap: phpredis backoff options
 *   (OPT_BACKOFF_ALGORITHM / OPT_BACKOFF_BASE / OPT_BACKOFF_CAP, base and
 *   cap configured in milliseconds).
 */
final readonly class RetryPolicy
{
    public const string BACKOFF_DEFAULT = 'default';

    public const array BACKOFF_ALGORITHMS = [
        'default',
        'constant',
        'uniform',
        'exponential',
        'full_jitter',
        'equal_jitter',
        'decorrelated_jitter',
    ];

    public function __construct(
        public int $commandRetries = 0,
        public ?int $maxRetries = null,
        public ?float $retryIntervalSeconds = null,
        public string $backoffAlgorithm = self::BACKOFF_DEFAULT,
        public ?float $backoffBase = null,
        public ?float $backoffCap = null,
    ) {
        if (!\in_array($this->backoffAlgorithm, self::BACKOFF_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                "Algorithm [{$this->backoffAlgorithm}] is not a valid backoff algorithm."
            );
        }
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Build a policy from a Laravel redis configuration array. Millisecond
     * options (retry_interval, backoff_base, backoff_cap) are converted to
     * seconds.
     */
    public static function fromParameters(array $params): self
    {
        return new self(
            commandRetries: (int) ($params['command_retries'] ?? 0),
            maxRetries: isset($params['max_retries']) ? (int) $params['max_retries'] : null,
            retryIntervalSeconds: isset($params['retry_interval'])
                ? ((float) $params['retry_interval']) / 1000.0
                : null,
            backoffAlgorithm: (string) ($params['backoff_algorithm'] ?? self::BACKOFF_DEFAULT),
            backoffBase: isset($params['backoff_base']) ? ((float) $params['backoff_base']) / 1000.0 : null,
            backoffCap: isset($params['backoff_cap']) ? ((float) $params['backoff_cap']) / 1000.0 : null,
        );
    }

    public function isDefault(): bool
    {
        return $this->commandRetries === 0
            && $this->maxRetries === null
            && $this->retryIntervalSeconds === null
            && $this->backoffAlgorithm === self::BACKOFF_DEFAULT
            && $this->backoffBase === null
            && $this->backoffCap === null;
    }
}
