<?php

namespace Fledge\Fiber\Http;

use Fledge\Async\Http\Client\Connection\DefaultConnectionFactory;
use Fledge\Async\Http\Client\Connection\UnlimitedConnectionPool;
use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\HttpConnectSocketConnector;
use Fledge\Async\Stream\SocketConnector;
use Fledge\Async\Stream\Socks5SocketConnector;
use Psr\Http\Message\UriInterface;

/**
 * Builds the async HTTP clients used by FledgeHandler.
 *
 * The default client disables the transport's own redirect and retry
 * interceptors (followRedirects(0) and retry(0) null both out in
 * HttpClientBuilder), so the layers above own those behaviors again:
 * Guzzle's RedirectMiddleware handles allow_redirects with all its options
 * (max, protocols, strict 307/308 replays, referer handling, per-hop
 * cookies, effective URI tracking), and Laravel's Http::retry is the only
 * retry mechanism instead of stacking on a hidden RetryRequests(2).
 *
 * Requests carrying TLS or proxy options (verify, cert, ssl_key,
 * crypto_method, proxy, decode_content) get a client built for that exact
 * option tuple, cached with a small LRU so repeated calls reuse connection
 * pools. The protocol version is per request via ALPN and never part of
 * the tuple.
 */
class AsyncClientFactory
{
    protected const CACHE_LIMIT = 32;

    protected ?HttpClient $default = null;

    protected ?string $defaultKey = null;

    /** @var array<string, HttpClient> */
    protected array $clients = [];

    /**
     * The default client: no transport redirects, no transport retries.
     */
    public function default(): HttpClient
    {
        return $this->default ??= (new HttpClientBuilder)
            ->followRedirects(0)
            ->retry(0)
            ->build();
    }

    /**
     * Resolve the client for a request's TLS, proxy, and decoding options.
     */
    public function clientFor(array $options, UriInterface $uri): HttpClient
    {
        $tuple = $this->normalize($options, $uri);
        $key = \json_encode($tuple, \JSON_THROW_ON_ERROR);

        $this->defaultKey ??= \json_encode($this->defaultTuple(), \JSON_THROW_ON_ERROR);

        if ($key === $this->defaultKey) {
            return $this->default();
        }

        if (isset($this->clients[$key])) {
            $client = $this->clients[$key];

            // Refresh the LRU position.
            unset($this->clients[$key]);

            return $this->clients[$key] = $client;
        }

        $client = $this->build($tuple);

        $this->clients[$key] = $client;

        if (\count($this->clients) > static::CACHE_LIMIT) {
            \array_shift($this->clients);
        }

        return $client;
    }

    /**
     * Normalize the cache-relevant request options into a stable tuple.
     *
     * @return array{verify: bool|string, cert: array{string, ?string}|null, ssl_key: array{string, ?string}|null, crypto: ?int, proxy: ?string, decode: bool}
     */
    protected function normalize(array $options, UriInterface $uri): array
    {
        $verify = $options['verify'] ?? true;

        if (\is_string($verify)) {
            $verify = \realpath($verify) ?: $verify;
        } else {
            $verify = (bool) $verify;
        }

        return [
            'verify' => $verify,
            'cert' => $this->normalizeCertificate($options['cert'] ?? null),
            'ssl_key' => $this->normalizeCertificate($options['ssl_key'] ?? null),
            'crypto' => isset($options['crypto_method']) ? (int) $options['crypto_method'] : null,
            'proxy' => $this->resolveProxy($options, $uri),
            'decode' => ($options['decode_content'] ?? true) !== false,
        ];
    }

    /**
     * @return array{verify: true, cert: null, ssl_key: null, crypto: null, proxy: null, decode: true}
     */
    protected function defaultTuple(): array
    {
        return [
            'verify' => true,
            'cert' => null,
            'ssl_key' => null,
            'crypto' => null,
            'proxy' => null,
            'decode' => true,
        ];
    }

    /**
     * Normalize Guzzle's cert and ssl_key forms (path or [path, passphrase]).
     *
     * @return array{string, ?string}|null
     */
    protected function normalizeCertificate(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $passphrase = null;

        if (\is_array($value)) {
            $passphrase = isset($value[1]) ? (string) $value[1] : null;
            $value = (string) ($value[0] ?? '');

            if ($value === '') {
                return null;
            }
        }

        $path = \realpath((string) $value) ?: (string) $value;

        return [$path, $passphrase];
    }

    /**
     * Resolve the proxy request option to the proxy URI for the target, or
     * null for a direct connection. Accepts Guzzle's string form and array
     * form with per-scheme proxies and 'no' exclusions.
     */
    protected function resolveProxy(array $options, UriInterface $uri): ?string
    {
        $proxy = $options['proxy'] ?? null;

        if ($proxy === null || $proxy === '') {
            return null;
        }

        if (!\is_array($proxy)) {
            return (string) $proxy;
        }

        $candidate = $proxy[$uri->getScheme()] ?? null;

        if ($candidate === null || $candidate === '') {
            return null;
        }

        $host = $uri->getHost();
        $noProxy = (array) ($proxy['no'] ?? []);

        if ($host !== '' && $noProxy !== [] && static::isHostInNoProxy($host, $noProxy)) {
            return null;
        }

        return (string) $candidate;
    }

    /**
     * Guzzle 8 moved the no_proxy matcher from Utils to ProxyOptions.
     *
     * @param list<string> $noProxy
     */
    protected static function isHostInNoProxy(string $host, array $noProxy): bool
    {
        if (\class_exists(\GuzzleHttp\ProxyOptions::class)) {
            return \GuzzleHttp\ProxyOptions::isHostInNoProxy($host, $noProxy);
        }

        /** @phpstan-ignore-next-line only reached on Guzzle 7, where the method exists */
        return \GuzzleHttp\Utils::isHostInNoProxy($host, $noProxy);
    }

    /**
     * Build a client for a normalized option tuple.
     *
     * The TLS context deliberately carries no peer name: the connection
     * factory sets it per request and preserves everything else configured
     * here.
     */
    protected function build(array $tuple): HttpClient
    {
        $tlsContext = new ClientTlsContext('');

        if ($tuple['verify'] === false) {
            $tlsContext = $tlsContext->withoutPeerVerification();
        } elseif (\is_string($tuple['verify'])) {
            $tlsContext = \is_dir($tuple['verify'])
                ? $tlsContext->withCaPath($tuple['verify'])
                : $tlsContext->withCaFile($tuple['verify']);
        }

        if ($tuple['cert'] !== null) {
            [$certPath, $certPassphrase] = $tuple['cert'];
            [$keyPath, $keyPassphrase] = $tuple['ssl_key'] ?? [null, null];

            $tlsContext = $tlsContext->withCertificate(new Certificate(
                $certPath,
                $keyPath ?? $certPath,
                $keyPassphrase ?? $certPassphrase,
            ));
        }

        if ($tuple['crypto'] !== null) {
            $tlsContext = $tlsContext->withMinimumVersion($tuple['crypto']);
        }

        $connectContext = (new ConnectContext)->withTlsContext($tlsContext);

        $factory = new DefaultConnectionFactory($this->connectorFor($tuple['proxy']), $connectContext);

        $builder = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool($factory))
            ->followRedirects(0)
            ->retry(0);

        if (!$tuple['decode']) {
            $builder = $builder->skipAutomaticCompression();
        }

        return $builder->build();
    }

    /**
     * Build the socket connector for the resolved proxy URI, or null for a
     * direct connection.
     */
    protected function connectorFor(?string $proxy): ?SocketConnector
    {
        if ($proxy === null) {
            return null;
        }

        $parts = \parse_url($proxy);

        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("Invalid proxy URI '{$proxy}'");
        }

        $scheme = \strtolower($parts['scheme'] ?? 'http');

        $port = $parts['port'] ?? match ($scheme) {
            'socks5', 'socks5h' => 1080,
            'http' => 80,
            default => null,
        };

        if ($port === null) {
            throw new \InvalidArgumentException("Unsupported proxy scheme '{$scheme}' in '{$proxy}', use http:// or socks5://");
        }

        $authority = 'tcp://'.$parts['host'].':'.$port;
        $username = isset($parts['user']) ? \rawurldecode($parts['user']) : null;
        $password = isset($parts['pass']) ? \rawurldecode($parts['pass']) : null;

        return match ($scheme) {
            'socks5', 'socks5h' => new Socks5SocketConnector($authority, $username, $password),
            'http' => new HttpConnectSocketConnector($authority, $username, $password),
            default => throw new \InvalidArgumentException("Unsupported proxy scheme '{$scheme}' in '{$proxy}', use http:// or socks5://"),
        };
    }
}
