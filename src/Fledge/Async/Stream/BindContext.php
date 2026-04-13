<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use function Fledge\Async\Stream\Internal\normalizeBindToOption;

final class BindContext
{
    private ?string $bindTo = null;

    /** @var positive-int */
    private int $backlog = 128;

    private bool $reusePort = false;
    private bool $broadcast = false;
    private bool $tcpNoDelay = false;

    private ?ServerTlsContext $tlsContext = null;

    public function withoutBindTo(): self
    {
        return $this->withBindTo(null);
    }

    public function withBindTo(?string $bindTo): self
    {
        $bindTo = normalizeBindToOption($bindTo);

        return clone($this, ['bindTo' => $bindTo]);
    }

    public function getBindTo(): ?string
    {
        return $this->bindTo;
    }

    public function getBacklog(): int
    {
        return $this->backlog;
    }

    /**
     * @param positive-int $backlog
     */
    public function withBacklog(int $backlog): self
    {
        return clone($this, ['backlog' => $backlog]);
    }

    public function hasReusePort(): bool
    {
        return $this->reusePort;
    }

    public function withReusePort(): self
    {
        return clone($this, ['reusePort' => true]);
    }

    public function withoutReusePort(): self
    {
        return clone($this, ['reusePort' => false]);
    }

    public function hasBroadcast(): bool
    {
        return $this->broadcast;
    }

    public function withBroadcast(): self
    {
        return clone($this, ['broadcast' => true]);
    }

    public function withoutBroadcast(): self
    {
        return clone($this, ['broadcast' => false]);
    }

    public function hasTcpNoDelay(): bool
    {
        return $this->tcpNoDelay;
    }

    public function withTcpNoDelay(): self
    {
        return clone($this, ['tcpNoDelay' => true]);
    }

    public function withoutTcpNoDelay(): self
    {
        return clone($this, ['tcpNoDelay' => false]);
    }

    public function getTlsContext(): ?ServerTlsContext
    {
        return $this->tlsContext;
    }

    public function withoutTlsContext(): self
    {
        return $this->withTlsContext(null);
    }

    public function withTlsContext(?ServerTlsContext $tlsContext): self
    {
        return clone($this, ['tlsContext' => $tlsContext]);
    }

    public function toStreamContextArray(): array
    {
        $array = [
            'socket' => [
                'bindto' => $this->bindTo,
                'backlog' => $this->backlog,
                'ipv6_v6only' => true,
                // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                'so_reuseaddr' => $this->reusePort && \PHP_OS_FAMILY === 'Windows',
                'so_reuseport' => $this->reusePort,
                'so_broadcast' => $this->broadcast,
                'tcp_nodelay' => $this->tcpNoDelay,
            ],
        ];

        if ($this->tlsContext) {
            $array = \array_merge($array, $this->tlsContext->toStreamContextArray());
        }

        return $array;
    }
}
