<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

use Closure;
use Fledge\Async\Redis\Connection\BackoffStrategy;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisException;

use function Fledge\Async\Redis\createRedisConnector;

final class ClusteringRedisLink implements RedisLink
{
    private const MAX_REDIRECTS = 5;

    private readonly ClusterTopology $topology;

    /** @var array<string, RedisLink> */
    private array $nodeLinks = [];

    private bool $inTransaction = false;

    private bool $pendingMulti = false;

    private ?string $pinnedNode = null;

    /**
     * @var Closure(RedisConfig|string $config): RedisLink
     */
    private readonly Closure $linkFactory;

    /**
     * @param  list<RedisConfig|string>  $seeds  structured configs (or legacy URIs) for each cluster seed
     * @param  Closure(string $endpoint): (RedisConfig|string)  $configForEndpoint  builds a config from "host:port" using shared options
     * @param  (Closure(RedisConfig|string $config): RedisLink)|null  $linkFactory  optional link factory (defaults to ReconnectingRedisLink)
     */
    public function __construct(
        private readonly array $seeds,
        private readonly Closure $configForEndpoint,
        ?Closure $linkFactory = null,
    ) {
        if ($seeds === []) {
            throw new \InvalidArgumentException('ClusteringRedisLink requires at least one seed.');
        }

        $this->linkFactory = $linkFactory ?? static function (RedisConfig|string $config): RedisLink {
            if ($config instanceof RedisConfig) {
                $policy = $config->getRetryPolicy();

                return new ReconnectingRedisLink(
                    createRedisConnector($config),
                    $config->getReadTimeout(),
                    BackoffStrategy::fromRetryPolicy($policy),
                    $policy->maxRetries,
                );
            }

            return new ReconnectingRedisLink(createRedisConnector($config));
        };
        $this->topology = new ClusterTopology();
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $upper = \strtoupper($command);

        if ($upper === 'MULTI') {
            return $this->beginTransaction();
        }

        if ($upper === 'EXEC' || $upper === 'DISCARD') {
            return $this->endTransaction($command, $parameters);
        }

        if ($this->inTransaction) {
            return $this->routeWithinTransaction($command, $parameters);
        }

        if ($this->topology->isStale()) {
            $this->bootstrapTopology();
        }

        $keys = CommandKeyExtractor::extract($command, $parameters);

        if ($keys === null || $keys === []) {
            return $this->dispatch($this->topology->slotMap()->randomMaster(), $command, $parameters);
        }

        $slot = SlotHasher::slotFor($keys[0]);

        for ($i = 1, $n = \count($keys); $i < $n; $i++) {
            if (SlotHasher::slotFor($keys[$i]) !== $slot) {
                return new RedisError('CROSSSLOT Keys in request don\'t hash to the same slot');
            }
        }

        $node = $this->topology->slotMap()->nodeForSlot($slot);

        return $this->dispatchWithRedirects($node, $command, $parameters);
    }

    /**
     * MULTI has to land on the same node that owns the slot of the keys used inside
     * the transaction. We don't know that slot yet, so we mark MULTI as pending and
     * dispatch it lazily right before the first keyed command.
     */
    private function beginTransaction(): RedisResponse
    {
        $this->inTransaction = true;
        $this->pendingMulti = true;
        $this->pinnedNode = null;

        return new RedisValue('OK');
    }

    /**
     * @param  list<int|string|float>  $parameters
     */
    private function endTransaction(string $command, array $parameters): RedisResponse
    {
        $upper = \strtoupper($command);
        $node = $this->pinnedNode;

        $this->inTransaction = false;
        $this->pinnedNode = null;
        $hadPendingMulti = $this->pendingMulti;
        $this->pendingMulti = false;

        if ($node === null) {
            // No keyed command was issued during the transaction, so MULTI was never sent.
            return new RedisValue($upper === 'EXEC' ? [] : 'OK');
        }

        if ($hadPendingMulti) {
            // MULTI was never flushed even though we somehow have a pinned node;
            // safest is to synthesize an OK for EXEC (empty result) or DISCARD.
            return new RedisValue($upper === 'EXEC' ? [] : 'OK');
        }

        return $this->dispatch($node, $command, $parameters);
    }

    /**
     * @param  list<int|string|float>  $parameters
     */
    private function routeWithinTransaction(string $command, array $parameters): RedisResponse
    {
        $keys = CommandKeyExtractor::extract($command, $parameters);

        if ($keys === null || $keys === []) {
            // Topology / no-key command inside MULTI. If we already pinned a node, use it;
            // otherwise pick a random master so the pending MULTI can be flushed.
            if ($this->pinnedNode === null) {
                if ($this->topology->isStale()) {
                    $this->bootstrapTopology();
                }
                $this->pinnedNode = $this->topology->slotMap()->randomMaster();
            }
        } else {
            $slot = SlotHasher::slotFor($keys[0]);

            for ($i = 1, $n = \count($keys); $i < $n; $i++) {
                if (SlotHasher::slotFor($keys[$i]) !== $slot) {
                    return new RedisError('CROSSSLOT Keys in request don\'t hash to the same slot');
                }
            }

            if ($this->topology->isStale()) {
                $this->bootstrapTopology();
            }

            $node = $this->topology->slotMap()->nodeForSlot($slot);

            if ($this->pinnedNode === null) {
                $this->pinnedNode = $node;
            } elseif ($this->pinnedNode !== $node) {
                return new RedisError('CROSSSLOT Cluster transaction crossed hash slots');
            }
        }

        if ($this->pendingMulti) {
            $multiResponse = $this->dispatch($this->pinnedNode, 'MULTI', []);

            if ($multiResponse instanceof RedisError) {
                $this->inTransaction = false;
                $this->pendingMulti = false;
                $this->pinnedNode = null;

                return $multiResponse;
            }

            $this->pendingMulti = false;
        }

        return $this->dispatch($this->pinnedNode, $command, $parameters);
    }

    /**
     * @param  list<int|string|float>  $parameters
     */
    private function dispatchWithRedirects(string $node, string $command, array $parameters): RedisResponse
    {
        $target = $node;
        $asking = false;

        for ($hop = 0; $hop < self::MAX_REDIRECTS; $hop++) {
            $response = $this->dispatch($target, $command, $parameters, $asking);
            $asking = false;

            if (!$response instanceof RedisError) {
                return $response;
            }

            if ($moved = MovedRedirect::tryParse($response)) {
                $target = $moved->endpoint();
                $this->topology->refresh($this->linkFor($target));

                continue;
            }

            if ($ask = AskRedirect::tryParse($response)) {
                $target = $ask->endpoint();
                $asking = true;

                continue;
            }

            return $response;
        }

        return new RedisError('CLUSTERDOWN Too many redirects following '.\strtoupper($command));
    }

    /**
     * @param  list<int|string|float>  $parameters
     */
    private function dispatch(string $node, string $command, array $parameters, bool $askingPrefix = false): RedisResponse
    {
        $link = $this->linkFor($node);

        if ($askingPrefix) {
            $link->execute('ASKING', [])->unwrap();
        }

        return $link->execute($command, $parameters);
    }

    private function linkFor(string $endpoint): RedisLink
    {
        if (!isset($this->nodeLinks[$endpoint])) {
            $config = ($this->configForEndpoint)($endpoint);
            $this->nodeLinks[$endpoint] = ($this->linkFactory)($config);
        }

        return $this->nodeLinks[$endpoint];
    }

    private function bootstrapTopology(): void
    {
        $errors = [];

        foreach ($this->seeds as $seed) {
            $endpoint = self::endpointFor($seed);

            if ($endpoint !== null && !isset($this->nodeLinks[$endpoint])) {
                $this->nodeLinks[$endpoint] = ($this->linkFactory)($seed);
            }

            $seedLink = $endpoint !== null
                ? $this->nodeLinks[$endpoint]
                : ($this->linkFactory)($seed);

            try {
                $this->topology->refresh($seedLink);

                return;
            } catch (\Throwable $exception) {
                $errors[] = ($endpoint ?? 'seed').': '.$exception->getMessage();
            }
        }

        throw new RedisException('Could not refresh cluster topology from any seed: '.\implode('; ', $errors));
    }

    /**
     * @return list<string>
     */
    public function masters(): array
    {
        if ($this->topology->isStale()) {
            $this->bootstrapTopology();
        }

        return $this->topology->slotMap()->masters();
    }

    public function executeOn(string $endpoint, string $command, array $parameters): RedisResponse
    {
        return $this->dispatch($endpoint, $command, $parameters);
    }

    private static function endpointFor(RedisConfig|string $seed): ?string
    {
        return $seed instanceof RedisConfig ? self::endpointFromConfig($seed) : self::endpointFromUri($seed);
    }

    /**
     * Config-aware sibling of endpointFromUri() using the structured host
     * and port, so seeds carrying TLS/auth/timeout options resolve to the
     * same "host:port" endpoint keys as MOVED/ASK redirect targets.
     */
    public static function endpointFromConfig(RedisConfig $config): string
    {
        $host = $config->getHost();
        $port = $config->getPort();

        if (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            return '['.$host.']:'.$port;
        }

        return $host.':'.$port;
    }

    private static function endpointFromUri(string $uri): ?string
    {
        $parts = \parse_url($uri);

        if ($parts === false || !isset($parts['host'], $parts['port'])) {
            return null;
        }

        $host = $parts['host'];
        $port = (int) $parts['port'];

        if (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            return '['.$host.']:'.$port;
        }

        return $host.':'.$port;
    }
}
