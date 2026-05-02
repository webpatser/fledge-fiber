<?php

use Fledge\Async\Redis\Cluster\ClusteringRedisLink;
use Fledge\Async\Redis\Cluster\SlotHasher;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;

final class StubRedisLink implements RedisLink
{
    /** @var list<array{string, array<int|string|float>}> */
    public array $log = [];

    /** @var list<RedisResponse|callable(string, array): RedisResponse> */
    public array $responses = [];

    public function __construct(public readonly string $endpoint)
    {
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $this->log[] = [$command, $parameters];

        if ($this->responses === []) {
            return new RedisValue('OK');
        }

        $next = array_shift($this->responses);

        if (is_callable($next)) {
            return $next($command, $parameters);
        }

        return $next;
    }
}

/**
 * @param  list<string>  $endpoints  endpoints to register stubs for
 * @param  list<string>  $seedEndpoints  subset to use as seed URIs (defaults to all)
 * @return array{ClusteringRedisLink, array<string, StubRedisLink>}
 */
function clusterLinkWithStubs(array $endpoints, ?array $seedEndpoints = null): array
{
    $byEndpoint = [];

    foreach ($endpoints as $endpoint) {
        $byEndpoint[$endpoint] = new StubRedisLink($endpoint);
    }

    $byUri = [];

    foreach ($byEndpoint as $endpoint => $stub) {
        $byUri["tcp://{$endpoint}"] = $stub;
    }

    $seeds = array_map(fn (string $endpoint) => "tcp://{$endpoint}", $seedEndpoints ?? $endpoints);

    $factory = function (string $uri) use ($byUri) {
        if (! isset($byUri[$uri])) {
            throw new RuntimeException("No stub configured for {$uri}");
        }

        return $byUri[$uri];
    };

    $uriForEndpoint = fn (string $endpoint) => "tcp://{$endpoint}";

    return [new ClusteringRedisLink($seeds, $uriForEndpoint, $factory), $byEndpoint];
}

function clusterSlotsResponse(): RedisResponse
{
    return new RedisValue([
        [0, 5460, ['127.0.0.1', 17000, 'node-1']],
        [5461, 10922, ['127.0.0.1', 17001, 'node-2']],
        [10923, 16383, ['127.0.0.1', 17002, 'node-3']],
    ]);
}

it('routes a key to the master that owns its slot', function () {
    [$link, $stubs] = clusterLinkWithStubs(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);

    // Bootstrap: 17000 (first seed) returns the topology.
    $stubs['127.0.0.1:17000']->responses[] = clusterSlotsResponse();
    // foo (slot 12182) belongs to 17002 (range 10923-16383).
    $stubs['127.0.0.1:17002']->responses[] = new RedisValue('value-from-server');

    $response = $link->execute('GET', ['foo']);

    expect($response->unwrap())->toBe('value-from-server');

    $owner = $stubs['127.0.0.1:17002'];

    expect($owner->log[0][0])->toBe('GET')
        ->and($owner->log[0][1])->toBe(['foo']);
});

it('returns a synthetic CROSSSLOT error when keys hash to different slots', function () {
    [$link, $stubs] = clusterLinkWithStubs(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);

    $stubs['127.0.0.1:17000']->responses[] = clusterSlotsResponse();

    $response = $link->execute('MGET', ['foo', 'bar']);

    expect($response)->toBeInstanceOf(RedisError::class)
        ->and($response->getKind())->toBe('CROSSSLOT');
});

it('follows MOVED redirects and refreshes topology', function () {
    [$link, $stubs] = clusterLinkWithStubs(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);

    // Bootstrap: first contacted seed (17000) returns the topology.
    $stubs['127.0.0.1:17000']->responses[] = clusterSlotsResponse();

    // Initial slot map says foo (slot 12182) is on 17002. Make 17002 reply with MOVED to 17001.
    $stubs['127.0.0.1:17002']->responses[] = new RedisError('MOVED 12182 127.0.0.1:17001');

    // After MOVED, the link refreshes topology against the redirect target (17001).
    $stubs['127.0.0.1:17001']->responses[] = new RedisValue([
        [0, 5460, ['127.0.0.1', 17000, 'node-1']],
        [5461, 16383, ['127.0.0.1', 17001, 'node-2']],
    ]);

    // Then the retry on 17001 returns the actual value.
    $stubs['127.0.0.1:17001']->responses[] = new RedisValue('redirected-value');

    $response = $link->execute('GET', ['foo']);

    expect($response->unwrap())->toBe('redirected-value')
        ->and($stubs['127.0.0.1:17002']->log[0][0])->toBe('GET')
        ->and($stubs['127.0.0.1:17001']->log[1][0])->toBe('GET');
});

it('handles ASK redirects by sending ASKING on the new node', function () {
    [$link, $stubs] = clusterLinkWithStubs(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);

    $stubs['127.0.0.1:17000']->responses[] = clusterSlotsResponse();
    $stubs['127.0.0.1:17002']->responses[] = new RedisError('ASK 12182 127.0.0.1:17001');

    $stubs['127.0.0.1:17001']->responses[] = new RedisValue('OK'); // ASKING reply
    $stubs['127.0.0.1:17001']->responses[] = new RedisValue('asked-value');

    $response = $link->execute('GET', ['foo']);

    expect($response->unwrap())->toBe('asked-value')
        ->and($stubs['127.0.0.1:17001']->log[0][0])->toBe('ASKING')
        ->and($stubs['127.0.0.1:17001']->log[1][0])->toBe('GET');
});

it('pins a node for the duration of MULTI/EXEC', function () {
    [$link, $stubs] = clusterLinkWithStubs(['127.0.0.1:17000']);

    // Single-node topology so the random pick lands on 17000 deterministically.
    $stubs['127.0.0.1:17000']->responses = [
        new RedisValue([[0, 16383, ['127.0.0.1', 17000, 'node-1']]]), // bootstrap CLUSTER SLOTS
        new RedisValue('OK'),                                          // MULTI
        new RedisValue('QUEUED'),                                      // SET
        new RedisValue('QUEUED'),                                      // GET
        new RedisValue([new RedisValue('OK'), new RedisValue('value')]),// EXEC
    ];

    expect($link->execute('MULTI', [])->unwrap())->toBe('OK');
    expect($link->execute('SET', ['{tag}.k', 'v'])->unwrap())->toBe('QUEUED');
    expect($link->execute('GET', ['{tag}.k'])->unwrap())->toBe('QUEUED');

    $exec = $link->execute('EXEC', [])->unwrap();

    expect($exec)->toBeArray()
        ->and(count($stubs['127.0.0.1:17000']->log))->toBe(5);
});

it('routes topology commands to a random master', function () {
    [$link, $stubs] = clusterLinkWithStubs(['127.0.0.1:17000']);

    $stubs['127.0.0.1:17000']->responses[] = new RedisValue([[0, 16383, ['127.0.0.1', 17000, 'node-1']]]);
    $stubs['127.0.0.1:17000']->responses[] = new RedisValue('PONG');

    $response = $link->execute('PING', []);

    expect($response->unwrap())->toBe('PONG');
});
