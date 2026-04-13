<?php declare(strict_types=1);

namespace Fledge\Async\Dns;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

final class StaticDnsConfigLoader implements DnsConfigLoader
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly DnsConfig $config
    ) {
    }

    public function loadConfig(): DnsConfig
    {
        return $this->config;
    }
}
