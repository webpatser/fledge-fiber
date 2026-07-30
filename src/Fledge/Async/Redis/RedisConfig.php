<?php declare(strict_types=1);

namespace Fledge\Async\Redis;

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
        return new self($uri, $timeout);
    }

    private string $uri;
    private string $host;
    private string $username;
    private string $password;
    private int $database;
    private float $timeout;
    private bool $tls;

    /**
     * @throws RedisException
     */
    private function __construct(string $uri, float $timeout)
    {
        $this->applyUri($uri);
        $this->timeout = $timeout;
    }

    public function getConnectUri(): string
    {
        return $this->uri;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
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

    /**
     * True when the URI requested a TLS connection via the "rediss" scheme.
     */
    public function usesTls(): bool
    {
        return $this->tls;
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

        $this->host = $uri->getHost() ?: self::DEFAULT_HOST;

        if ($scheme === 'unix') {
            $this->uri = 'unix://' . $uri->getPath();
            return;
        }

        $path = \ltrim($uri->getPath(), '/');
        if ($path !== '') {
            $this->database = (int) $path;
        }

        $this->uri = \sprintf(
            'tcp://%s:%d',
            $this->host,
            $uri->getPort() ?: self::DEFAULT_PORT,
        );
    }
}
