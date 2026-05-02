<?php declare(strict_types=1);

namespace Fledge\Async\Redis\Cluster;

use Closure;
use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
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
     * @var Closure(string $uri): RedisLink
     */
    private readonly Closure $linkFactory;

    /**
     * @param  list<string>  $seedUris  full Redis URIs for each cluster seed (e.g. "tcp://host:port?password=...")
     * @param  Closure(string $endpoint): string  $uriForEndpoint  builds a URI from "host:port" using shared config
     * @param  (Closure(string $uri): RedisLink)|null  $linkFactory  optional link factory (defaults to ReconnectingRedisLink)
     */
    public function __construct(
        private readonly array $seedUris,
        private readonly Closure $uriForEndpoint,
        ?Closure $linkFactory = null,
    ) {
        if ($seedUris === []) {
            throw new \InvalidArgumentException('ClusteringRedisLink requires at least one seed URI.');
        }

        $this->linkFactory = $linkFactory ?? static fn (string $uri) => new ReconnectingRedisLink(createRedisConnector($uri));
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
            $uri = ($this->uriForEndpoint)($endpoint);
            $this->nodeLinks[$endpoint] = ($this->linkFactory)($uri);
        }

        return $this->nodeLinks[$endpoint];
    }

    private function bootstrapTopology(): void
    {
        $errors = [];

        foreach ($this->seedUris as $uri) {
            $endpoint = self::endpointFromUri($uri);

            if ($endpoint !== null && !isset($this->nodeLinks[$endpoint])) {
                $this->nodeLinks[$endpoint] = ($this->linkFactory)($uri);
            }

            $seedLink = $endpoint !== null
                ? $this->nodeLinks[$endpoint]
                : ($this->linkFactory)($uri);

            try {
                $this->topology->refresh($seedLink);

                return;
            } catch (\Throwable $exception) {
                $errors[] = $uri.': '.$exception->getMessage();
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
