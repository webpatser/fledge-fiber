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

    private bool $keepAlive = false;
    private ?int $keepAliveIdle = null;
    private ?int $keepAliveInterval = null;
    private ?int $keepAliveCount = null;
    private ?int $receiveBufferSize = null;
    private ?int $sendBufferSize = null;

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

    public function hasKeepAlive(): bool
    {
        return $this->keepAlive;
    }

    /**
     * Enables SO_KEEPALIVE. The idle time, probe interval and probe count (TCP_KEEPIDLE,
     * TCP_KEEPINTVL, TCP_KEEPCNT) are only set when given; otherwise the OS defaults apply.
     */
    public function withKeepAlive(?int $idle = null, ?int $interval = null, ?int $count = null): self
    {
        foreach (['idle' => $idle, 'interval' => $interval, 'count' => $count] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new \ValueError("Invalid keep-alive {$name} ({$value}), must be greater than 0");
            }
        }

        return clone($this, [
            'keepAlive' => true,
            'keepAliveIdle' => $idle,
            'keepAliveInterval' => $interval,
            'keepAliveCount' => $count,
        ]);
    }

    public function withoutKeepAlive(): self
    {
        return clone($this, [
            'keepAlive' => false,
            'keepAliveIdle' => null,
            'keepAliveInterval' => null,
            'keepAliveCount' => null,
        ]);
    }

    public function getKeepAliveIdle(): ?int
    {
        return $this->keepAliveIdle;
    }

    public function getKeepAliveInterval(): ?int
    {
        return $this->keepAliveInterval;
    }

    public function getKeepAliveCount(): ?int
    {
        return $this->keepAliveCount;
    }

    public function getReceiveBufferSize(): ?int
    {
        return $this->receiveBufferSize;
    }

    public function withReceiveBufferSize(int $bytes): self
    {
        if ($bytes < 1 || $bytes > 2147483647) {
            throw new \ValueError("Invalid receive buffer size ({$bytes}), must be between 1 and 2147483647");
        }

        return clone($this, ['receiveBufferSize' => $bytes]);
    }

    public function withoutReceiveBufferSize(): self
    {
        return clone($this, ['receiveBufferSize' => null]);
    }

    public function getSendBufferSize(): ?int
    {
        return $this->sendBufferSize;
    }

    public function withSendBufferSize(int $bytes): self
    {
        if ($bytes < 1 || $bytes > 2147483647) {
            throw new \ValueError("Invalid send buffer size ({$bytes}), must be between 1 and 2147483647");
        }

        return clone($this, ['sendBufferSize' => $bytes]);
    }

    public function withoutSendBufferSize(): self
    {
        return clone($this, ['sendBufferSize' => null]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withKeepAliveAndBufferOptions(array $options): array
    {
        if ($this->keepAlive) {
            $options['so_keepalive'] = true;
            $this->keepAliveIdle !== null && $options['tcp_keepidle'] = $this->keepAliveIdle;
            $this->keepAliveInterval !== null && $options['tcp_keepintvl'] = $this->keepAliveInterval;
            $this->keepAliveCount !== null && $options['tcp_keepcnt'] = $this->keepAliveCount;
        }

        $this->receiveBufferSize !== null && $options['so_rcvbuf'] = $this->receiveBufferSize;
        $this->sendBufferSize !== null && $options['so_sndbuf'] = $this->sendBufferSize;

        return $options;
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
            'socket' => $this->withKeepAliveAndBufferOptions([
                'bindto' => $this->bindTo,
                'backlog' => $this->backlog,
                'ipv6_v6only' => true,
                // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                'so_reuseaddr' => $this->reusePort && \PHP_OS_FAMILY === 'Windows',
                'so_reuseport' => $this->reusePort,
                'so_broadcast' => $this->broadcast,
                'tcp_nodelay' => $this->tcpNoDelay,
            ]),
        ];

        if ($this->tlsContext) {
            $array = \array_merge($array, $this->tlsContext->toStreamContextArray());
        }

        return $array;
    }
}
