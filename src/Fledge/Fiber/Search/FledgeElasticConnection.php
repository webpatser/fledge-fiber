<?php

declare(strict_types=1);

namespace Fledge\Fiber\Search;

use Illuminate\Container\Container;
use PDPhilip\Elasticsearch\Connection;
use PDPhilip\Elasticsearch\ElasticClient;

/**
 * PDPhilip Elasticsearch connection that transports through Fledge's fiber-aware
 * HTTP client (see ElasticClientFactory) instead of Guzzle's default curl handler.
 */
final class FledgeElasticConnection extends Connection
{
    #[\Override]
    protected function createConnection(): ElasticClient
    {
        $builder = Container::getInstance()->make(ElasticClientFactory::class)->builder($this->config);

        $builder = $this->builderOptions($builder);

        return new ElasticClient($builder->build());
    }
}
