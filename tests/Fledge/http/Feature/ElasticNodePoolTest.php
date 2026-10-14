<?php

use Elastic\Elasticsearch\Client;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastic\Transport\NodePool\Resurrect\NoResurrect;
use Elastic\Transport\NodePool\Selector\RoundRobin;
use Elastic\Transport\NodePool\SimpleNodePool;
use Fledge\Fiber\Search\ElasticClientFactory;
use Fledge\Fiber\Search\ResurrectingNodePool;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle handler standing in for an Elasticsearch cluster: records the host of
 * every request and fails with a network error while told to.
 */
final class NodePoolTestCluster
{
    /** @var list<string> */
    public array $requests = [];

    /** Fail this many upcoming requests, whatever their host. */
    public int $failNext = 0;

    /** @var array<string, int> Upcoming failures per host. */
    public array $down = [];

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $host = $request->getUri()->getHost();
        $this->requests[] = $host;

        if ($this->failNext > 0 || ($this->down[$host] ?? 0) > 0) {
            $this->failNext > 0 ? $this->failNext-- : $this->down[$host]--;

            return Create::rejectionFor(new ConnectException("Operation timed out after 10000 milliseconds ({$host})", $request));
        }

        return Create::promiseFor(new Response(200, [
            'X-Elastic-Product' => 'Elasticsearch',
            'Content-Type' => 'application/json',
        ], '{"tagline":"You Know, for Search"}'));
    }
}

/** A caller-supplied pool: the transport default, built without constructor arguments. */
final class NodePoolTestPool extends SimpleNodePool
{
    public function __construct()
    {
        parent::__construct(new RoundRobin, new NoResurrect);
    }
}

/**
 * Factory-built client on the fake cluster. Retries default to 0 so every
 * network error surfaces; ClientBuilder treats 0 as unset, hence the transport.
 *
 * @param  array<string, mixed>  $config
 */
function nodePoolClient(NodePoolTestCluster $cluster, array $config, int $retries = 0, ?Closure $tap = null): Client
{
    $builder = (new ElasticClientFactory)->builder($config)
        ->setHttpClientOptions(['handler' => HandlerStack::create($cluster)]);

    if ($tap !== null) {
        $tap($builder);
    }

    $client = $builder->build();
    $client->getTransport()->setRetries($retries);

    return $client;
}

/** A clock the test moves by hand. */
final class NodePoolTestClock
{
    public float $now = 1000.0;

    public function __invoke(): float
    {
        return $this->now;
    }
}

/**
 * Connection config whose node pool (default 5s window) runs on the test clock.
 *
 * @param  list<string>  $hosts
 * @return array<string, mixed>
 */
function nodePoolClockedConfig(NodePoolTestClock $clock, array $hosts): array
{
    return [
        'hosts' => $hosts,
        'node_pool' => new ResurrectingNodePool(ResurrectingNodePool::DEFAULT_RESURRECT_AFTER, Closure::fromCallable($clock)),
    ];
}

function nodePoolResurrectAfter(Client $client): float
{
    $pool = $client->getTransport()->getNodePool();

    expect($pool)->toBeInstanceOf(ResurrectingNodePool::class);

    return (new ReflectionProperty($pool, 'resurrectAfter'))->getValue($pool);
}

afterEach(fn () => Container::setInstance(null));

it('makes one HTTP attempt on a failing single host even with retries, then fails fast', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 1;
    $clock = new NodePoolTestClock;
    $client = nodePoolClient($cluster, nodePoolClockedConfig($clock, ['http://es:9200']), retries: 3);

    // One timeout marks the host dead; the transport's three retries find no node and stop.
    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class)
        ->and($cluster->requests)->toBe(['es']);
});

it('fails fast without an HTTP attempt while a dead host is inside its window', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 1;
    $clock = new NodePoolTestClock;
    $client = nodePoolClient($cluster, nodePoolClockedConfig($clock, ['http://es:9200']), retries: 3);

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class);

    $clock->now += 4.9;

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class, 'No alive nodes')
        ->and(fn () => $client->info())->toThrow(NoNodeAvailableException::class, 'No alive nodes')
        ->and($cluster->requests)->toBe(['es']);
});

it('retries the dead host once its window has passed and recovers', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 1;
    $clock = new NodePoolTestClock;
    $client = nodePoolClient($cluster, nodePoolClockedConfig($clock, ['http://es:9200']), retries: 3);

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class);

    $clock->now += 5.1;

    expect($client->info()->getStatusCode())->toBe(200)
        ->and($client->info()->getStatusCode())->toBe(200)
        ->and($cluster->requests)->toBe(['es', 'es', 'es']);
});

it('marks the host dead again when the attempt after the window fails too', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 2;
    $clock = new NodePoolTestClock;
    $client = nodePoolClient($cluster, nodePoolClockedConfig($clock, ['http://es:9200']), retries: 3);

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class);

    $clock->now += 5.1;

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class)
        ->and($cluster->requests)->toBe(['es', 'es']);

    // The window restarts at the second failure.
    $clock->now += 4.9;

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class, 'No alive nodes')
        ->and($cluster->requests)->toBe(['es', 'es']);

    $clock->now += 0.2;

    expect($client->info()->getStatusCode())->toBe(200)
        ->and($cluster->requests)->toBe(['es', 'es', 'es']);
});

it('fails over to the alive host within the same request', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 1;
    $clock = new NodePoolTestClock;
    $client = nodePoolClient($cluster, nodePoolClockedConfig($clock, ['http://es-a:9200', 'http://es-b:9200']), retries: 1);

    expect($client->info()->getStatusCode())->toBe(200)
        ->and($cluster->requests)->toHaveCount(2)
        ->and($cluster->requests[1])->not->toBe($cluster->requests[0]);

    $dead = $cluster->requests[0];

    for ($i = 0; $i < 4; $i++) {
        $client->info();
    }

    expect(array_slice($cluster->requests, 2))->each->not->toBe($dead);

    $clock->now += 5.1;

    expect($client->info()->getStatusCode())->toBe(200)
        ->and(end($cluster->requests))->toBe($dead);
});

it('fails fast when every host is dead and none is past its window, then retries them', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->down = ['es-a' => 1, 'es-b' => 1];
    $clock = new NodePoolTestClock;
    $client = nodePoolClient($cluster, nodePoolClockedConfig($clock, ['http://es-a:9200', 'http://es-b:9200']), retries: 3);

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class);

    [$first, $second] = $cluster->requests;

    expect($cluster->requests)->toHaveCount(2)
        ->and($second)->not->toBe($first)
        ->and(fn () => $client->info())->toThrow(NoNodeAvailableException::class, 'No alive nodes')
        ->and($cluster->requests)->toHaveCount(2);

    $clock->now += 5.1;

    expect($client->info()->getStatusCode())->toBe(200)
        ->and($cluster->requests)->toHaveCount(3);
});

it('skips a dead host until resurrect_after has passed, then tries it again', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 1;
    $client = nodePoolClient($cluster, [
        'hosts' => ['http://es-a:9200', 'http://es-b:9200'],
        'options' => ['resurrect_after' => 0.2],
    ]);

    expect(fn () => $client->info())->toThrow(NoNodeAvailableException::class);

    $dead = $cluster->requests[0];

    for ($i = 0; $i < 4; $i++) {
        expect($client->info()->getStatusCode())->toBe(200);
    }

    $alive = array_slice($cluster->requests, 1);

    expect(array_unique($alive))->toHaveCount(1)
        ->and($alive[0])->not->toBe($dead);

    usleep(250_000);

    expect($client->info()->getStatusCode())->toBe(200)
        ->and(end($cluster->requests))->toBe($dead);
});

it('keeps round-robin across healthy hosts', function () {
    $cluster = new NodePoolTestCluster;
    $client = nodePoolClient($cluster, ['hosts' => ['http://es-a:9200', 'http://es-b:9200', 'http://es-c:9200']]);

    for ($i = 0; $i < 6; $i++) {
        $client->info();
    }

    $round = array_slice($cluster->requests, 0, 3);
    $sorted = $round;
    sort($sorted);

    expect($sorted)->toBe(['es-a', 'es-b', 'es-c'])
        ->and(array_slice($cluster->requests, 3))->toBe($round);
});

it('lets a node pool set on the builder win over the default', function () {
    $cluster = new NodePoolTestCluster;
    $cluster->failNext = 1;
    $client = nodePoolClient(
        $cluster,
        ['hosts' => ['http://es:9200']],
        tap: fn ($builder) => $builder->setNodePool(new SimpleNodePool(new RoundRobin, new NoResurrect)),
    );

    expect($client->getTransport()->getNodePool())->toBeInstanceOf(SimpleNodePool::class)
        ->and(fn () => $client->info())->toThrow(NoNodeAvailableException::class)
        ->and(fn () => $client->info())->toThrow(NoNodeAvailableException::class, 'No alive nodes')
        ->and($cluster->requests)->toBe(['es']);
});

it('reads resurrect_after from the connection config', function (array $config, float $expected) {
    $client = nodePoolClient(new NodePoolTestCluster, ['hosts' => ['http://es:9200'], ...$config]);

    expect(nodePoolResurrectAfter($client))->toBe($expected);
})->with([
    'default' => [[], ResurrectingNodePool::DEFAULT_RESURRECT_AFTER],
    'top level' => [['resurrect_after' => 1.5], 1.5],
    'under options as a string' => [['options' => ['resurrect_after' => '0.5']], 0.5],
    'empty string' => [['options' => ['resurrect_after' => '']], ResurrectingNodePool::DEFAULT_RESURRECT_AFTER],
    'negative' => [['resurrect_after' => -3], 0.0],
]);

it('takes node_pool from the connection config', function (mixed $nodePool, string $expected) {
    $client = nodePoolClient(new NodePoolTestCluster, ['hosts' => ['http://es:9200'], 'node_pool' => $nodePool]);

    expect($client->getTransport()->getNodePool())->toBeInstanceOf($expected);
})->with([
    'false keeps the transport default' => [false, SimpleNodePool::class],
    'class name' => [NodePoolTestPool::class, NodePoolTestPool::class],
    'instance' => [new NodePoolTestPool, NodePoolTestPool::class],
]);

it('takes node_pool from options as well', function () {
    $client = nodePoolClient(new NodePoolTestCluster, ['hosts' => ['http://es:9200'], 'options' => ['node_pool' => NodePoolTestPool::class]]);

    expect($client->getTransport()->getNodePool())->toBeInstanceOf(NodePoolTestPool::class);
});

it('rejects a node_pool class that is not a node pool', function () {
    (new ElasticClientFactory)->builder(['hosts' => ['http://es:9200'], 'node_pool' => stdClass::class]);
})->throws(InvalidArgumentException::class, 'does not implement');
