<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

use Fledge\Async\Redis\Connection\RetryPolicy;
use League\Uri\Uri;

final class RedisConfig
{
    public const string DEFAULT_HOST = 'localhost';
    public const int DEFAULT_PORT = 6379;
    public const int DEFAULT_TIMEOUT = 5;

    /**
     * @throws RedisException
     */
    public static function fromUri(string $uri, float $timeout = self::DEFAULT_TIMEOUT): self
    {
        $config = new self();
        $config->timeout = $timeout;
        $config->applyUri($uri);

        return $config;
    }

    /**
     * Build a config from a Laravel-style redis connection parameter array,
     * preserving options that do not survive a round-trip through a URI.
     *
     * Connect-URI derivation:
     * - unix socket when the scheme is "unix", a "path" key is present
     *   (predis style), the host starts with "/" (phpredis style), or the
     *   port is 0 and the host looks like a filesystem path
     * - TLS when the scheme is "tls" or "rediss"
     * - plain tcp://host:port otherwise
     *
     * @throws RedisException
     */
    public static function fromParameters(array $params): self
    {
        $config = new self();

        $scheme = \strtolower((string) ($params['scheme'] ?? 'tcp'));
        $host = (string) ($params['host'] ?? '127.0.0.1');
        $port = (int) ($params['port'] ?? self::DEFAULT_PORT);

        $config->host = $host;
        $config->port = $port;
        $config->username = (string) ($params['username'] ?? '');
        $config->password = (string) ($params['password'] ?? '');
        $config->database = (int) ($params['database'] ?? 0);

        $timeout = (float) ($params['timeout'] ?? self::DEFAULT_TIMEOUT);
        $config->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;

        // phpredis semantics: a read_timeout of zero or less means "wait forever".
        $readTimeout = isset($params['read_timeout']) ? (float) $params['read_timeout'] : null;
        $config->readTimeout = ($readTimeout !== null && $readTimeout > 0) ? $readTimeout : null;

        $config->tlsOptions = \is_array($params['context'] ?? null) ? $params['context'] : [];
        $config->clientName = isset($params['name']) && (string) $params['name'] !== ''
            ? (string) $params['name']
            : null;
        $config->tcpKeepalive = !empty($params['tcp_keepalive']);
        $config->retryPolicy = RetryPolicy::fromParameters($params);

        $unixPath = match (true) {
            $scheme === 'unix' => (string) ($params['path'] ?? $host),
            isset($params['path']) => (string) $params['path'],
            \str_starts_with($host, '/') => $host,
            $port === 0 && \str_contains($host, '/') => $host,
            default => null,
        };

        if ($unixPath !== null) {
            $config->uri = 'unix://' . $unixPath;

            return $config;
        }

        $config->tls = $scheme === 'tls' || $scheme === 'rediss';
        $config->uri = \sprintf('tcp://%s:%d', $host, $port);

        return $config;
    }

    private string $uri = 'tcp://' . self::DEFAULT_HOST . ':' . self::DEFAULT_PORT;
    private string $host = self::DEFAULT_HOST;
    private int $port = self::DEFAULT_PORT;
    private string $username = '';
    private string $password = '';
    private int $database = 0;
    private float $timeout = self::DEFAULT_TIMEOUT;
    private ?float $readTimeout = null;
    private bool $tls = false;
    private array $tlsOptions = [];
    private ?string $clientName = null;
    private RetryPolicy $retryPolicy;
    private bool $tcpKeepalive = false;

    private function __construct()
    {
        $this->retryPolicy = RetryPolicy::default();
    }

    public function getConnectUri(): string
    {
        return $this->uri;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * Maximum time to wait for a single command response, or null to wait
     * forever (phpredis read_timeout semantics: zero or negative means
     * no limit and is normalized to null).
     */
    public function getReadTimeout(): ?float
    {
        return $this->readTimeout;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function hasPassword(): bool
    {
        return $this->password !== '';
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function hasUsername(): bool
    {
        return $this->username !== '';
    }

    /**
     * The host the connection targets, used as the TLS peer name.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * True when the configuration requested a TLS connection via the
     * "rediss" or "tls" scheme.
     */
    public function usesTls(): bool
    {
        return $this->tls;
    }

    /**
     * Raw stream context ssl options as configured (already normalized to a
     * flat option array by the connector).
     */
    public function getTlsOptions(): array
    {
        return $this->tlsOptions;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function getRetryPolicy(): RetryPolicy
    {
        return $this->retryPolicy;
    }

    public function usesTcpKeepalive(): bool
    {
        return $this->tcpKeepalive;
    }

    public function getDatabase(): int
    {
        return $this->database;
    }

    public function withTimeout(float $timeout): self
    {
        return clone($this, ['timeout' => $timeout]);
    }

    public function withPassword(string $password): self
    {
        return clone($this, ['password' => $password]);
    }

    public function withUsername(string $username): self
    {
        return clone($this, ['username' => $username]);
    }

    public function withTls(bool $tls = true): self
    {
        return clone($this, ['tls' => $tls]);
    }

    public function withDatabase(int $database): self
    {
        return clone($this, ['database' => $database]);
    }

    /**
     * When using the "redis" schemes the URI is parsed according to the rules defined by the provisional registration
     * documents approved by IANA. If the URI has a username or password in its "user-information" part, or a database
     * number in the "path" part, these values override the values of "username" / "password" / "database" if they are
     * present in the "query" part.
     *
     * The "user-information" part is percent-encoded per RFC 3986, so both components are decoded here: a password
     * containing reserved characters such as "+", "/", "=", "@" or ":" must be encoded by the caller and arrives
     * intact. The "rediss" scheme selects a TLS connection.
     *
     * @link http://www.iana.org/assignments/uri-schemes/prov/redis
     *
     * @param string $uri URI string.
     *
     * @throws RedisException
     */
    private function applyUri(string $uri): void
    {
        try {
            $uri = Uri::new($uri);
        } catch (\Exception) {
            throw new RedisException('Invalid redis configuration URI: ' . $uri);
        }

        $rawScheme = \strtolower($uri->getScheme() ?? '');

        $scheme = match ($rawScheme) {
            'tcp', 'redis', 'rediss' => 'tcp',
            'unix' => 'unix',
            default => throw new RedisException(
                'Invalid scheme for redis URI, must be tcp, unix, redis, or rediss, got ' . $uri->getScheme()
            ),
        };

        $this->tls = $rawScheme === 'rediss';

        \parse_str($uri->getQuery() ?? '', $query);

        // Split before decoding: an encoded ":" inside either component must not act as the separator.
        [$username, $password] = \explode(':', $uri->getUserInfo() ?? '', 2) + [null, null];

        $this->username = match (true) {
            $username !== null && $username !== '' => \rawurldecode($username),
            default => (string) ($query['username'] ?? $query['user'] ?? ''),
        };

        $this->password = match (true) {
            $password !== null => \rawurldecode($password),
            default => (string) ($query['password'] ?? $query['pass'] ?? ''),
        };

        $this->database = (int) ($query['database'] ?? $query['db'] ?? 0);

        if (isset($query['timeout']) && \is_numeric($query['timeout'])) {
            $this->timeout = (float) $query['timeout'];
        }

        $this->host = $uri->getHost() ?: self::DEFAULT_HOST;

        if ($scheme === 'unix') {
            $this->uri = 'unix://' . $uri->getPath();
            return;
        }

        $path = \ltrim($uri->getPath(), '/');
        if ($path !== '') {
            $this->database = (int) $path;
        }

        $this->port = $uri->getPort() ?: self::DEFAULT_PORT;

        $this->uri = \sprintf(
            'tcp://%s:%d',
            $this->host,
            $this->port,
        );
    }
}
