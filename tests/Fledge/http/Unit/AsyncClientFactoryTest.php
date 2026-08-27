<?php

use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\InterceptedHttpClient;
use Fledge\Fiber\Http\AsyncClientFactory;

/**
 * Collect the application interceptor classes wired into a built client by
 * walking the InterceptedHttpClient chain down to the pooled client.
 *
 * @return list<class-string>
 */
function applicationInterceptorClasses(HttpClient $client): array
{
    $classes = [];

    $inner = (new ReflectionProperty(HttpClient::class, 'httpClient'))->getValue($client);

    while ($inner instanceof InterceptedHttpClient) {
        $classes[] = (new ReflectionProperty(InterceptedHttpClient::class, 'interceptor'))->getValue($inner)::class;

        $inner = (new ReflectionProperty(InterceptedHttpClient::class, 'httpClient'))->getValue($inner);
    }

    return $classes;
}

it('builds a default client without transport redirects and retries', function () {
    $client = (new AsyncClientFactory)->default();

    $classes = applicationInterceptorClasses($client);

    expect($classes)->not->toContain(\Fledge\Async\Http\Client\Interceptor\FollowRedirects::class)
        ->and($classes)->not->toContain(\Fledge\Async\Http\Client\Interceptor\RetryRequests::class);
});

it('caches the default client instance', function () {
    $factory = new AsyncClientFactory;

    expect($factory->default())->toBe($factory->default());
});

it('returns the default client for the default option tuple', function () {
    $factory = new AsyncClientFactory;
    $uri = new \GuzzleHttp\Psr7\Uri('https://example.com');

    expect($factory->clientFor([], $uri))->toBe($factory->default())
        ->and($factory->clientFor(['timeout' => 5, 'allow_redirects' => false], $uri))->toBe($factory->default());
});

it('keeps the protocol version out of the cache key', function () {
    $factory = new AsyncClientFactory;
    $uri = new \GuzzleHttp\Psr7\Uri('https://example.com');

    expect($factory->clientFor(['version' => 2.0], $uri))->toBe($factory->default());
});

it('caches clients per normalized option tuple', function () {
    $factory = new AsyncClientFactory;
    $uri = new \GuzzleHttp\Psr7\Uri('https://example.com');

    $insecure = $factory->clientFor(['verify' => false], $uri);

    expect($insecure)->not->toBe($factory->default())
        ->and($factory->clientFor(['verify' => false], $uri))->toBe($insecure);
});

it('builds distinct clients for distinct verify, proxy, and decode options', function () {
    $factory = new AsyncClientFactory;
    $uri = new \GuzzleHttp\Psr7\Uri('https://example.com');

    $insecure = $factory->clientFor(['verify' => false], $uri);
    $proxied = $factory->clientFor(['proxy' => 'socks5://127.0.0.1:1080'], $uri);
    $raw = $factory->clientFor(['decode_content' => false], $uri);

    expect($proxied)->not->toBe($insecure)
        ->and($raw)->not->toBe($insecure)
        ->and($raw)->not->toBe($proxied);
});

it('resolves guzzle array proxies by target scheme with no exclusions', function () {
    $factory = new AsyncClientFactory;
    $httpsUri = new \GuzzleHttp\Psr7\Uri('https://example.com');
    $excludedUri = new \GuzzleHttp\Psr7\Uri('https://internal.local');

    $proxyOption = [
        'proxy' => [
            'http' => 'http://plain.proxy:8080',
            'https' => 'socks5://secure.proxy:1080',
            'no' => ['.local'],
        ],
    ];

    $viaProxy = $factory->clientFor($proxyOption, $httpsUri);
    $direct = $factory->clientFor($proxyOption, $excludedUri);

    expect($viaProxy)->not->toBe($factory->default())
        ->and($viaProxy)->toBe($factory->clientFor(['proxy' => 'socks5://secure.proxy:1080'], $httpsUri))
        ->and($direct)->toBe($factory->default());
});

it('rejects https scheme proxies', function () {
    (new AsyncClientFactory)->clientFor(
        ['proxy' => 'https://secure.proxy:443'],
        new \GuzzleHttp\Psr7\Uri('https://example.com'),
    );
})->throws(InvalidArgumentException::class, 'Unsupported proxy scheme');

it('evicts the least recently used client beyond the cache limit', function () {
    $factory = new AsyncClientFactory;
    $uri = new \GuzzleHttp\Psr7\Uri('https://example.com');

    $first = $factory->clientFor(['verify' => false], $uri);

    foreach (range(1, 32) as $i) {
        $factory->clientFor(['proxy' => "socks5://proxy{$i}.test:1080"], $uri);
    }

    expect($factory->clientFor(['verify' => false], $uri))->not->toBe($first);
});
