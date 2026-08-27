<?php declare(strict_types=1);

namespace Fledge\Async\Database\Postgres;

use Fledge\Async\Database\SqlConfig;

final class PostgresConfig extends SqlConfig
{
    public const int DEFAULT_PORT = 5432;

    public const SSL_MODES = [
        'disable',
        'allow',
        'prefer',
        'require',
        'verify-ca',
        'verify-full',
    ];

    public const KEY_MAP = [
        ...parent::KEY_MAP,
        'ssl_mode' => 'sslmode',
        'sslMode' => 'sslmode',
        'applicationName' => 'application_name',
        'options' => 'options',
        'keepalivesIdle' => 'keepalives_idle',
        'keepalivesInterval' => 'keepalives_interval',
        'keepalivesCount' => 'keepalives_count',
        'sslCert' => 'sslcert',
        'sslKey' => 'sslkey',
        'sslRootCert' => 'sslrootcert',
    ];

    private ?string $connectionString = null;

    public static function fromString(string $connectionString): self
    {
        $parts = self::parseConnectionString($connectionString, self::KEY_MAP);

        if (!isset($parts["host"])) {
            throw new \Error("Host must be provided in connection string");
        }

        return new self(
            $parts["host"],
            (int) ($parts["port"] ?? self::DEFAULT_PORT),
            $parts["user"] ?? null,
            $parts["password"] ?? null,
            $parts["db"] ?? null,
            $parts["application_name"] ?? null,
            $parts["sslmode"] ?? null,
            $parts["options"] ?? null,
            isset($parts["keepalives"]) ? (int) $parts["keepalives"] : null,
            isset($parts["keepalives_idle"]) ? (int) $parts["keepalives_idle"] : null,
            isset($parts["keepalives_interval"]) ? (int) $parts["keepalives_interval"] : null,
            isset($parts["keepalives_count"]) ? (int) $parts["keepalives_count"] : null,
            $parts["sslcert"] ?? null,
            $parts["sslkey"] ?? null,
            $parts["sslrootcert"] ?? null,
        );
    }

    public function __construct(
        string $host,
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        private ?string $applicationName = null,
        private ?string $sslMode = null,
        private ?string $options = null,
        private ?int $keepalives = null,
        private ?int $keepalivesIdle = null,
        private ?int $keepalivesInterval = null,
        private ?int $keepalivesCount = null,
        private ?string $sslCert = null,
        private ?string $sslKey = null,
        private ?string $sslRootCert = null,
    ) {
        self::assertValidSslMode($sslMode);

        parent::__construct($host, $port, $user, $password, $database);
    }

    public function __clone()
    {
        $this->connectionString = null;
    }

    public function getSslMode(): ?string
    {
        return $this->sslMode;
    }

    private static function assertValidSslMode(?string $mode): void
    {
        if ($mode === null) {
            return;
        }

        if (!\in_array($mode, self::SSL_MODES, true)) {
            throw new \Error('Invalid SSL mode, must be one of: ' . \implode(', ', self::SSL_MODES));
        }
    }

    public function withSslMode(string $mode): self
    {
        self::assertValidSslMode($mode);

        return clone($this, ['sslMode' => $mode]);
    }

    public function withoutSslMode(): self
    {
        return clone($this, ['sslMode' => null]);
    }

    public function getApplicationName(): ?string
    {
        return $this->applicationName;
    }

    public function withApplicationName(string $name): self
    {
        return clone($this, ['applicationName' => $name]);
    }

    public function withoutApplicationName(): self
    {
        return clone($this, ['applicationName' => null]);
    }

    public function getOptions(): ?string
    {
        return $this->options;
    }

    public function withOptions(string $options): self
    {
        return clone($this, ['options' => $options]);
    }

    public function withoutOptions(): self
    {
        return clone($this, ['options' => null]);
    }

    public function getKeepalives(): ?int
    {
        return $this->keepalives;
    }

    public function withKeepalives(int $keepalives): self
    {
        return clone($this, ['keepalives' => $keepalives]);
    }

    public function withoutKeepalives(): self
    {
        return clone($this, ['keepalives' => null]);
    }

    public function getKeepalivesIdle(): ?int
    {
        return $this->keepalivesIdle;
    }

    public function withKeepalivesIdle(int $seconds): self
    {
        return clone($this, ['keepalivesIdle' => $seconds]);
    }

    public function withoutKeepalivesIdle(): self
    {
        return clone($this, ['keepalivesIdle' => null]);
    }

    public function getKeepalivesInterval(): ?int
    {
        return $this->keepalivesInterval;
    }

    public function withKeepalivesInterval(int $seconds): self
    {
        return clone($this, ['keepalivesInterval' => $seconds]);
    }

    public function withoutKeepalivesInterval(): self
    {
        return clone($this, ['keepalivesInterval' => null]);
    }

    public function getKeepalivesCount(): ?int
    {
        return $this->keepalivesCount;
    }

    public function withKeepalivesCount(int $count): self
    {
        return clone($this, ['keepalivesCount' => $count]);
    }

    public function withoutKeepalivesCount(): self
    {
        return clone($this, ['keepalivesCount' => null]);
    }

    public function getSslCert(): ?string
    {
        return $this->sslCert;
    }

    public function withSslCert(string $path): self
    {
        return clone($this, ['sslCert' => $path]);
    }

    public function withoutSslCert(): self
    {
        return clone($this, ['sslCert' => null]);
    }

    public function getSslKey(): ?string
    {
        return $this->sslKey;
    }

    public function withSslKey(string $path): self
    {
        return clone($this, ['sslKey' => $path]);
    }

    public function withoutSslKey(): self
    {
        return clone($this, ['sslKey' => null]);
    }

    public function getSslRootCert(): ?string
    {
        return $this->sslRootCert;
    }

    public function withSslRootCert(string $path): self
    {
        return clone($this, ['sslRootCert' => $path]);
    }

    public function withoutSslRootCert(): self
    {
        return clone($this, ['sslRootCert' => null]);
    }

    /**
     * @return string Connection string used with ext-pgsql and pecl-pq.
     */
    public function getConnectionString(): string
    {
        if ($this->connectionString !== null) {
            return $this->connectionString;
        }

        $chunks = [];

        // An empty host lets libpq fall back to its default (unix socket or
        // localhost), matching how the pgsql driver treats an omitted host.
        if ($this->getHost() !== '') {
            $chunks[] = "host=" . $this->getHost();
        }

        $chunks[] = "port=" . $this->getPort();

        $user = $this->getUser();
        if ($user !== null) {
            $chunks[] = \sprintf("user='%s'", \addslashes($user));
        }

        $password = $this->getPassword();
        if ($password !== null) {
            $chunks[] = \sprintf("password='%s'", \addslashes($password));
        }

        $database = $this->getDatabase();
        if ($database !== null) {
            $chunks[] = \sprintf("dbname='%s'", \addslashes($database));
        }

        if ($this->sslMode !== null) {
            $chunks[] = "sslmode=" . $this->sslMode;
        }

        if ($this->applicationName !== null) {
            $chunks[] = \sprintf("application_name='%s'", \addslashes($this->applicationName));
        }

        if ($this->options !== null) {
            $chunks[] = \sprintf("options='%s'", \addslashes($this->options));
        }

        if ($this->keepalives !== null) {
            $chunks[] = "keepalives=" . $this->keepalives;
        }

        if ($this->keepalivesIdle !== null) {
            $chunks[] = "keepalives_idle=" . $this->keepalivesIdle;
        }

        if ($this->keepalivesInterval !== null) {
            $chunks[] = "keepalives_interval=" . $this->keepalivesInterval;
        }

        if ($this->keepalivesCount !== null) {
            $chunks[] = "keepalives_count=" . $this->keepalivesCount;
        }

        if ($this->sslCert !== null) {
            $chunks[] = \sprintf("sslcert='%s'", \addslashes($this->sslCert));
        }

        if ($this->sslKey !== null) {
            $chunks[] = \sprintf("sslkey='%s'", \addslashes($this->sslKey));
        }

        if ($this->sslRootCert !== null) {
            $chunks[] = \sprintf("sslrootcert='%s'", \addslashes($this->sslRootCert));
        }

        return $this->connectionString = \implode(" ", $chunks);
    }
}
