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
