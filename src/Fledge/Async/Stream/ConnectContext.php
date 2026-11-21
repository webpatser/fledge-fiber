<?php declare(strict_types=1);

namespace Fledge\Async\Stream;

use Fledge\Async\Dns\DnsRecord;
use function Fledge\Async\Stream\Internal\normalizeBindToOption;

final class ConnectContext
{
    private ?string $bindTo = null;

    private float $connectTimeout = 10;

    private ?int $typeRestriction = null;

    private bool $tcpNoDelay = false;

    private bool $keepAlive = false;
    private ?int $keepAliveIdle = null;
    private ?int $keepAliveInterval = null;
    private ?int $keepAliveCount = null;
    private ?int $receiveBufferSize = null;
    private ?int $sendBufferSize = null;

    private ?ClientTlsContext $tlsContext = null;

    public function withoutBindTo(): self
    {
        return $this->withBindTo(null);
    }

    public function withBindTo(?string $bindTo): self
    {
        return clone($this, ['bindTo' => normalizeBindToOption($bindTo)]);
    }

    public function getBindTo(): ?string
    {
        return $this->bindTo;
    }

    public function withConnectTimeout(float $timeout): self
    {
        if ($timeout <= 0) {
            throw new \ValueError("Invalid connect timeout ({$timeout}), must be greater than 0");
        }

        return clone($this, ['connectTimeout' => $timeout]);
    }

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function withoutDnsTypeRestriction(): self
    {
        return $this->withDnsTypeRestriction(null);
    }

    public function withDnsTypeRestriction(?int $type): self
    {
        if ($type !== null && $type !== DnsRecord::AAAA && $type !== DnsRecord::A) {
            throw new \ValueError('Invalid resolver type restriction');
        }

        return clone($this, ['typeRestriction' => $type]);
    }

    public function getDnsTypeRestriction(): ?int
    {
        return $this->typeRestriction;
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
        if ($bytes < 1) {
            throw new \ValueError("Invalid receive buffer size ({$bytes}), must be greater than 0");
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
        if ($bytes < 1) {
            throw new \ValueError("Invalid send buffer size ({$bytes}), must be greater than 0");
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

    public function withoutTlsContext(): self
    {
        return $this->withTlsContext(null);
    }

    public function withTlsContext(?ClientTlsContext $tlsContext): self
    {
        return clone($this, ['tlsContext' => $tlsContext]);
    }

    public function getTlsContext(): ?ClientTlsContext
    {
        return $this->tlsContext;
    }

    public function toStreamContextArray(): array
    {
        $options = [
            'tcp_nodelay' => $this->tcpNoDelay,
        ];

        if ($this->bindTo !== null) {
            $options['bindto'] = $this->bindTo;
        }

        $array = ['socket' => $this->withKeepAliveAndBufferOptions($options)];

        if ($this->tlsContext) {
            $array = \array_merge($array, $this->tlsContext->toStreamContextArray());
        }

        return $array;
    }
}
