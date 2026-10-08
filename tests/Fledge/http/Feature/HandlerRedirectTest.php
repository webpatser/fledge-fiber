<?php

use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request as AsyncRequest;
use Fledge\Async\Http\Server\Request as ServerRequest;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\TimeoutCancellation;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\RedirectMiddleware;

require_once __DIR__.'/../Fixtures/loopback.php';

/**
 * Start the redirect loopback: /a -> /b -> /c, a 307 route, and a recorder
 * for what actually arrived at each path.
 *
 * @return array{\Fledge\Async\Http\Server\SocketHttpServer, int, ArrayObject}
 */
function startRedirectLoopback(): array
{
    $received = new ArrayObject;

    [$server, $port] = startLoopbackServer(null, function (ServerRequest $request) use ($received): ServerResponse {
        $path = $request->getUri()->getPath();

        $received[] = [
            'method' => $request->getMethod(),
            'path' => $path,
            'body' => $request->getBody()->buffer(),
            'referer' => $request->getHeader('referer'),
        ];

        return match ($path) {
            '/a' => new ServerResponse(302, ['location' => '/b']),
            '/b' => new ServerResponse(302, ['location' => '/c']),
            '/c' => new ServerResponse(200, [], 'end of chain'),
            '/307' => new ServerResponse(307, ['location' => '/target']),
            '/target' => new ServerResponse(200, [], 'target'),
            default => new ServerResponse(404, [], 'not found'),
        };
    });

    return [$server, $port, $received];
}

it('follows a redirect chain through guzzle redirect middleware', function () {
    [$server, $port, $received] = startRedirectLoopback();

    try {
        $response = makeGuzzleClient()->get("http://127.0.0.1:{$port}/a");

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('end of chain')
            ->and(array_column($received->getArrayCopy(), 'path'))->toBe(['/a', '/b', '/c']);
    } finally {
        $server->stop();
    }
});

it('returns the 3xx response when redirects are disabled', function () {
    [$server, $port] = startRedirectLoopback();

    try {
        $response = makeGuzzleClient(['allow_redirects' => false])->get("http://127.0.0.1:{$port}/a");

        expect($response->getStatusCode())->toBe(302)
            ->and($response->getHeaderLine('location'))->toBe('/b');
    } finally {
        $server->stop();
    }
});

it('enforces the redirect maximum', function () {
    [$server, $port] = startRedirectLoopback();

    try {
        makeGuzzleClient(['allow_redirects' => ['max' => 1]])->get("http://127.0.0.1:{$port}/a");

        $this->fail('Expected a TooManyRedirectsException');
    } catch (TooManyRedirectsException $e) {
        expect($e->getMessage())->toContain('1 redirects');
    } finally {
        $server->stop();
    }
});

it('replays the request body on a 307 redirect', function () {
    [$server, $port, $received] = startRedirectLoopback();

    try {
        $response = makeGuzzleClient()->post("http://127.0.0.1:{$port}/307", [
            'body' => 'replay me',
        ]);

        $target = array_values(array_filter($received->getArrayCopy(), fn (array $entry) => $entry['path'] === '/target'));

        expect($response->getStatusCode())->toBe(200)
            ->and($target)->toHaveCount(1)
            ->and($target[0]['method'])->toBe('POST')
            ->and($target[0]['body'])->toBe('replay me');
    } finally {
        $server->stop();
    }
});

it('does not leak the original path in cross-origin referers', function () {
    [$target, $targetPort, $targetReceived] = startRedirectLoopback();

    [$origin, $originPort] = startLoopbackServer(null, function () use ($targetPort): ServerResponse {
        return new ServerResponse(302, ['location' => "http://127.0.0.1:{$targetPort}/c"]);
    });

    try {
        $response = makeGuzzleClient([
            'allow_redirects' => ['max' => 5, 'referer' => true],
        ])->get("http://127.0.0.1:{$originPort}/secret-path?token=abc");

        $entries = $targetReceived->getArrayCopy();

        expect($response->getStatusCode())->toBe(200)
            ->and($entries)->toHaveCount(1)
            ->and((string) $entries[0]['referer'])->not->toContain('secret-path')
            ->and((string) $entries[0]['referer'])->not->toContain('token')
            ->and($entries[0]['referer'])->toBe("http://127.0.0.1:{$originPort}/");
    } finally {
        $origin->stop();
        $target->stop();
    }
});

it('keeps the full referer on same-origin redirects', function () {
    [$server, $port, $received] = startRedirectLoopback();

    try {
        $response = makeGuzzleClient([
            'allow_redirects' => ['max' => 5, 'referer' => true],
        ])->get("http://127.0.0.1:{$port}/a?token=abc");

        $referers = array_column($received->getArrayCopy(), 'referer', 'path');

        expect($response->getStatusCode())->toBe(200)
            ->and($referers['/b'])->toBe("http://127.0.0.1:{$port}/a?token=abc")
            ->and($referers['/c'])->toBe("http://127.0.0.1:{$port}/b");
    } finally {
        $server->stop();
    }
});

it('leaves a caller-set referer alone on the first request', function () {
    [$server, $port, $received] = startRedirectLoopback();

    try {
        makeGuzzleClient()->get("http://127.0.0.1:{$port}/c", [
            'headers' => ['Referer' => 'https://elsewhere.test/page?q=1'],
        ]);

        expect($received[0]['referer'])->toBe('https://elsewhere.test/page?q=1');
    } finally {
        $server->stop();
    }
});

it('sends only the origin as referer when the transport follows a cross-origin redirect', function () {
    [$target, $targetPort, $targetReceived] = startRedirectLoopback();

    [$origin, $originPort] = startLoopbackServer(null, function () use ($targetPort): ServerResponse {
        return new ServerResponse(302, ['location' => "http://127.0.0.1:{$targetPort}/c"]);
    });

    try {
        $client = (new HttpClientBuilder)->followRedirects(5)->build();
        $response = $client->request(
            new AsyncRequest("http://127.0.0.1:{$originPort}/secret-path?token=abc#frag"),
            new TimeoutCancellation(LOOPBACK_TIMEOUT),
        );
        $response->getBody()->buffer();

        $entries = $targetReceived->getArrayCopy();

        expect($response->getStatus())->toBe(200)
            ->and($entries)->toHaveCount(1)
            ->and($entries[0]['referer'])->toBe("http://127.0.0.1:{$originPort}/");
    } finally {
        $origin->stop();
        $target->stop();
    }
});

it('tracks redirect history and exposes the effective uri', function () {
    [$server, $port] = startRedirectLoopback();

    try {
        $response = makeGuzzleClient([
            'allow_redirects' => ['max' => 5, 'track_redirects' => true],
        ])->get("http://127.0.0.1:{$port}/a");

        $history = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);

        expect($response->getStatusCode())->toBe(200)
            ->and($history)->toBe([
                "http://127.0.0.1:{$port}/b",
                "http://127.0.0.1:{$port}/c",
            ]);
    } finally {
        $server->stop();
    }
});
