<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final readonly class MysqlColumnDefinition
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param non-empty-string $table
     * @param non-empty-string $name
     * @param int<0, max> $length
     * @param int<0, max> $flags
     * @param int<0, max> $decimals
     * @param non-empty-string|null $originalTable
     * @param non-empty-string|null $originalName
     * @param int<0, max>|null $charset
     * @param non-empty-string|null $catalog
     * @param non-empty-string|null $schema
     */
    public function __construct(
        private string $table,
        private string $name,
        private int $length,
        private MysqlDataType $type,
        private int $flags,
        private int $decimals,
        private string $defaults = '',
        private ?string $originalTable = null,
        private ?string $originalName = null,
        private ?int $charset = null,
        private ?string $catalog = null,
        private ?string $schema = null,
    ) {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int<0, max>
     */
    public function getLength(): int
    {
        return $this->length;
    }

    public function getType(): MysqlDataType
    {
        return $this->type;
    }

    /**
     * @return int<0, max>
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * @return int<0, max>
     */
    public function getDecimals(): int
    {
        return $this->decimals;
    }

    public function getDefaults(): string
    {
        return $this->defaults;
    }

    /**
     * @return non-empty-string|null
     */
    public function getOriginalTable(): ?string
    {
        return $this->originalTable;
    }

    /**
     * @return non-empty-string|null
     */
    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    /**
     * @return int<0, max>|null
     */
    public function getCharset(): ?int
    {
        return $this->charset;
    }

    /**
     * @return non-empty-string|null
     */
    public function getCatalog(): ?string
    {
        return $this->catalog;
    }

    /**
     * @return non-empty-string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }
}
