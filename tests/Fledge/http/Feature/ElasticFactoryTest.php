<?php

use Elastic\Elasticsearch\Client;
use Fledge\Fiber\Http\FiberHttpServiceProvider;
use Fledge\Fiber\Http\FledgeHandler;
use Fledge\Fiber\Search\ElasticClientFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;

function elasticGuzzleHandler(Client $client): mixed
{
    $guzzle = $client->getTransport()->getClient();

    expect($guzzle)->toBeInstanceOf(GuzzleClient::class);

    $stack = (new ReflectionProperty($guzzle, 'config'))->getValue($guzzle)['handler'];

    expect($stack)->toBeInstanceOf(HandlerStack::class);

    return (new ReflectionProperty($stack, 'handler'))->getValue($stack);
}

/** Minimal config: dotted get()/set(), which is all the provider uses. */
final class ElasticTestConfig
{
    public function __construct(private array $items) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }
}

final class ElasticTestApp extends Container
{
    public function configurationIsCached(): bool
    {
        return false;
    }
}

/** Provider with the PHPUnit guard lifted, as in a real application. */
final class ElasticTestProvider extends FiberHttpServiceProvider
{
    protected function integrationsActive(): bool
    {
        return true;
    }
}

/**
 * @return array<string, Closure>
 */
function elasticExtensions(?bool $flag): array
{
    $app = new ElasticTestApp;
    Container::setInstance($app);
    $app->instance('config', new ElasticTestConfig(
        $flag === null ? [] : ['fledge-http' => ['integrations' => ['elasticsearch' => $flag]]],
    ));
    $app->singleton('db', fn ($app) => new DatabaseManager($app, new ConnectionFactory($app)));

    (new ElasticTestProvider($app))->register();

    return (new ReflectionProperty(DatabaseManager::class, 'extensions'))->getValue($app['db']);
}

afterEach(fn () => Container::setInstance(null));

it('uses the Fledge handler with default options', function () {
    $client = (new ElasticClientFactory)->builder(['hosts' => ['http://es:9200']])->build();

    expect(elasticGuzzleHandler($client))->toBeInstanceOf(FledgeHandler::class);
});

it('keeps the Fledge handler when SSL verification is off', function () {
    $client = (new ElasticClientFactory)
        ->builder(['hosts' => ['https://es:9200']])
        ->setSSLVerification(false)
        ->build();

    expect(elasticGuzzleHandler($client))->toBeInstanceOf(FledgeHandler::class);
});

it('keeps the Fledge handler when a CA bundle is set', function () {
    $ca = tempnam(sys_get_temp_dir(), 'ca');

    try {
        $client = (new ElasticClientFactory)
            ->builder(['hosts' => ['https://es:9200'], 'ssl_cert' => $ca])
            ->build();

        $guzzle = $client->getTransport()->getClient();
        $config = (new ReflectionProperty($guzzle, 'config'))->getValue($guzzle);

        expect(elasticGuzzleHandler($client))->toBeInstanceOf(FledgeHandler::class)
            ->and($config['verify'])->toBe($ca);
    } finally {
        @unlink($ca);
    }
});

it('applies hosts from the connection config', function () {
    $builder = (new ElasticClientFactory)->builder(['hosts' => ['http://es-a:9200', 'http://es-b:9200']]);
    $pool = $builder->build()->getTransport()->getNodePool();
    $nodes = array_map(fn ($node) => $node->getUri()->getHost(), (new ReflectionProperty($pool, 'nodes'))->getValue($pool));

    sort($nodes);

    expect($nodes)->toBe(['es-a', 'es-b']);
});

it('registers the elasticsearch database driver by default', function () {
    expect(elasticExtensions(null))->toHaveKey('elasticsearch');
});

it('registers no driver when the flag is off', function () {
    expect(elasticExtensions(false))->not->toHaveKey('elasticsearch');
});
