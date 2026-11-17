<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Database\SqlConfig;

final class MysqlConfig extends SqlConfig
{
    public const DEFAULT_PORT = 3306;

    /** Largest payload the client accepts from the server: 1 GiB, the ceiling of MySQL's max_allowed_packet. */
    public const DEFAULT_MAX_PACKET_SIZE = 1024 * 1024 * 1024;
    public const BIN_CHARSET = 45; // utf8mb4_general_ci, understood by MySQL 5.7+ and MariaDB (255 is MySQL 8 only)

    public const KEY_MAP = [
        ...parent::KEY_MAP,
        'compress' => 'compression',
        'useCompression' => 'compression',
        'cs' => 'charset',
        'localInfile' => 'local-infile',
    ];

    public const DEFAULT_CHARSET = "utf8mb4";
    public const DEFAULT_COLLATE = "utf8mb4_0900_ai_ci";

    private ConnectContext $context;

    public static function fromString(string $connectionString, ?ConnectContext $context = null): self
    {
        $parts = self::parseConnectionString($connectionString, self::KEY_MAP);

        if (!isset($parts['host'])) {
            throw new \Error('Host must be provided in connection string');
        }

        return new self(
            host: $parts['host'],
            port: (int) ($parts['port'] ?? self::DEFAULT_PORT),
            user: $parts['user'] ?? null,
            password: $parts['password'] ?? null,
            database: $parts['db'] ?? null,
            context: $context,
            charset: $parts['charset'] ?? self::DEFAULT_CHARSET,
            collate: $parts['collate'] ?? '',
            useCompression: ($parts['compression'] ?? '') === 'on',
            useLocalInfile: ($parts['local-infile'] ?? '') === 'on'
        );
    }

    public static function fromAuthority(
        string $authority,
        string $user,
        string $password,
        ?string $database = null,
        ?ConnectContext $context = null,
    ): self {
        [$host, $port] = \explode(':', $authority, 2) + ['', (string) self::DEFAULT_PORT];

        return new self(
            host: $host,
            port: (int) $port,
            user: $user,
            password: $password,
            database: $database,
            context: $context,
        );
    }

    /**
     * @param string $key Pinned server RSA public key (PEM) for sha256_password and for caching_sha2_password
     *     full authentication over an unencrypted connection; like PDO::MYSQL_ATTR_SERVER_PUBLIC_KEY
     * @param int $maxPacketSize Largest (multi-packet) payload accepted from the server, in bytes
     * @param string|null $localInfileDirectory Without $useLocalInfile, only files inside this directory are
     *     sent for LOAD DATA LOCAL INFILE; like PDO::MYSQL_ATTR_LOCAL_INFILE_DIRECTORY
     * @param bool $secureKeyRetrieval Fledge only, see withSecureKeyRetrieval()
     */
    public function __construct(
        string $host,
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        ?ConnectContext $context = null,
        private string $charset = self::DEFAULT_CHARSET,
        private string $collate = '',
        private ?string $sqlMode = null,
        private bool $useCompression = false,
        private string $key = '',
        private bool $useLocalInfile = false,
        private int $maxPacketSize = self::DEFAULT_MAX_PACKET_SIZE,
        private ?string $localInfileDirectory = null,
        private bool $secureKeyRetrieval = false,
    ) {
        if ($maxPacketSize < 1) {
            throw new \ValueError('The maximum packet size must be at least 1 byte');
        }

        parent::__construct($host, $port, $user, $password, $database);

        $this->context = $context ?? new ConnectContext();
    }

    public function getConnectionString(): string
    {
        return $this->getHost()[0] == "/"
            ? 'unix://' . $this->getHost()
            : 'tcp://' . $this->getHost() . ':' . $this->getPort();
    }

    public function isCompressionEnabled(): bool
    {
        return $this->useCompression;
    }

    public function withCompression(): self
    {
        return clone($this, ['useCompression' => true]);
    }

    public function withoutCompression(): self
    {
        return clone($this, ['useCompression' => false]);
    }

    public function isLocalInfileEnabled(): bool
    {
        return $this->useLocalInfile;
    }

    public function withLocalInfile(): self
    {
        return clone($this, ['useLocalInfile' => true]);
    }

    public function withoutLocalInfile(): self
    {
        return clone($this, ['useLocalInfile' => false]);
    }

    public function getLocalInfileDirectory(): ?string
    {
        return $this->localInfileDirectory;
    }

    /**
     * Without withLocalInfile(), allow LOAD DATA LOCAL INFILE for files inside $directory only. As in
     * mysqlnd, withLocalInfile() ignores the restriction, but the directory must still be openable.
     * Pass null to remove it.
     */
    public function withLocalInfileDirectory(?string $directory): self
    {
        return clone($this, ['localInfileDirectory' => $directory]);
    }

    public function getMaxPacketSize(): int
    {
        return $this->maxPacketSize;
    }

    public function withMaxPacketSize(int $maxPacketSize): self
    {
        if ($maxPacketSize < 1) {
            throw new \ValueError('The maximum packet size must be at least 1 byte');
        }

        return clone($this, ['maxPacketSize' => $maxPacketSize]);
    }

    public function getConnectContext(): ConnectContext
    {
        return $this->context;
    }

    public function withConnectContext(ConnectContext $context): self
    {
        return clone($this, ['context' => $context]);
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getCollation(): string
    {
        return $this->collate;
    }

    public function withCharset(string $charset, string $collate): self
    {
        return clone($this, ['charset' => $charset, 'collate' => $collate]);
    }

    public function getSqlMode(): ?string
    {
        return $this->sqlMode;
    }

    /**
     * Set the Server SQL Modes at connection time.
     * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
     */
    public function withSqlMode(?string $sqlMode): self
    {
        return clone($this, ['sqlMode' => $sqlMode]);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function withKey(string $key): self
    {
        return clone($this, ['key' => $key]);
    }

    public function isSecureKeyRetrievalEnabled(): bool
    {
        return $this->secureKeyRetrieval;
    }

    /**
     * Fledge only, off by default (pdo_mysql behaviour). When on, caching_sha2_password full authentication
     * and sha256_password over a connection without TLS and without a key from withKey() fail instead of
     * fetching the server's RSA public key, which an attacker on the path could replace. With TLS, a unix
     * socket or a pinned key the setting changes nothing.
     */
    public function withSecureKeyRetrieval(bool $enabled = true): self
    {
        return clone($this, ['secureKeyRetrieval' => $enabled]);
    }
}
