<?php declare(strict_types=1);

namespace Fledge\Async\Dns;

interface DnsConfigLoader
{
    /**
     * @throws DnsConfigException
     */
    public function loadConfig(): DnsConfig;
}
