<?php

use Fledge\Async\Http\Server\Request as ServerRequest;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Fiber\Http\Symfony\FledgeSymfonyHttpClient;
use Fledge\Fiber\Http\Symfony\FledgeSymfonyResponse;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

require_once __DIR__.'/../Fixtures/loopback.php';

/**
 * Loopback for the Symfony adapter: /echo reflects the request as JSON,
 * plus status, redirect, slow, and large-body routes.
 *
 * @return array{SocketHttpServer, int}
 */
function startSymfonyLoopback(): array
{
    return startLoopbackServer(null, function (ServerRequest $request): ServerResponse {
        $uri = $request->getUri();

        return match ($uri->getPath()) {
            '/echo' => new ServerResponse(200, ['content-type' => 'application/json', 'x-custom' => 'yes'], json_encode([
                'method' => $request->getMethod(),
                'query' => $uri->getQuery(),
                'body' => $request->getBody()->buffer(),
                'content_type' => $request->getHeader('content-type'),
                'x_test' => $request->getHeader('x-test'),
                'authorization' => $request->getHeader('authorization'),
                'user_agent' => $request->getHeader('user-agent'),
            ])),
            '/404' => new ServerResponse(404, [], 'missing'),
            '/500' => new ServerResponse(500, [], 'broken'),
            '/redirect' => new ServerResponse(302, ['location' => '/echo?from=redirect']),
            '/slow' => (function (): ServerResponse {
                delay(0.3);

                return new ServerResponse(200, [], 'slow');
            })(),
            '/stall' => (function (): ServerResponse {
                delay(1);

                return new ServerResponse(200, [], 'late');
            })(),
            '/large' => new ServerResponse(200, [], str_repeat('abcdefgh', 64 * 1024)),
            default => new ServerResponse(404, [], 'not found'),
        };
    });
}

function symfonyClient(array $options = []): FledgeSymfonyHttpClient
{
    return new FledgeSymfonyHttpClient($options + ['timeout' => LOOPBACK_TIMEOUT, 'max_duration' => LOOPBACK_TIMEOUT]);
}

it('sends a GET with query and headers and exposes the response', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $response = symfonyClient()->request('GET', "http://127.0.0.1:{$port}/echo", [
            'query' => ['a' => '1'],
            'headers' => ['X-Test' => 'hello'],
            'auth_bearer' => 'token',
        ]);

        expect($response)->toBeInstanceOf(FledgeSymfonyResponse::class)
            ->and($response->getStatusCode())->toBe(200)
            ->and($response->getHeaders()['x-custom'])->toBe(['yes'])
            ->and($response->getInfo('http_code'))->toBe(200)
            ->and($response->getInfo('url'))->toBe("http://127.0.0.1:{$port}/echo?a=1")
            ->and($response->getInfo('response_headers')[0])->toStartWith('HTTP/1.1 200');

        $data = $response->toArray();

        expect($data['method'])->toBe('GET')
            ->and($data['query'])->toBe('a=1')
            ->and($data['x_test'])->toBe('hello')
            ->and($data['authorization'])->toBe('Bearer token')
            ->and($data['user_agent'])->toBe('Symfony HttpClient (Fledge)');
    } finally {
        $server->stop();
    }
});

it('posts a JSON body and a form body', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $client = symfonyClient();

        $json = $client->request('POST', "http://127.0.0.1:{$port}/echo", ['json' => ['name' => 'fledge']])->toArray();

        expect($json['method'])->toBe('POST')
            ->and($json['content_type'])->toBe('application/json')
            ->and(json_decode($json['body'], true))->toBe(['name' => 'fledge']);

        $form = $client->request('POST', "http://127.0.0.1:{$port}/echo", ['body' => ['a' => 'b']])->toArray();

        expect($form['content_type'])->toBe('application/x-www-form-urlencoded')
            ->and($form['body'])->toBe('a=b');
    } finally {
        $server->stop();
    }
});

it('follows Symfony status throw semantics', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $client = symfonyClient();

        $missing = $client->request('GET', "http://127.0.0.1:{$port}/404");

        expect($missing->getStatusCode())->toBe(404)
            ->and(fn () => $missing->getContent())->toThrow(ClientException::class)
            ->and(fn () => $missing->getHeaders())->toThrow(ClientException::class)
            ->and($missing->getContent(false))->toBe('missing');

        $broken = $client->request('GET', "http://127.0.0.1:{$port}/500");

        expect(fn () => $broken->toArray())->toThrow(ServerException::class)
            ->and($broken->getContent(false))->toBe('broken');

        // A response nobody checked throws when destroyed, as Symfony's do.
        expect(function () use ($client, $port) {
            $client->request('GET', "http://127.0.0.1:{$port}/404");
        })->toThrow(ClientException::class);

        // Checked with $throw = false: no throw on destruct.
        $client->request('GET', "http://127.0.0.1:{$port}/404")->getContent(false);
    } finally {
        $server->stop();
    }
});

it('follows redirects within max_redirects', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $followed = symfonyClient()->request('GET', "http://127.0.0.1:{$port}/redirect");

        expect($followed->toArray()['query'])->toBe('from=redirect')
            ->and($followed->getInfo('redirect_count'))->toBe(1)
            ->and($followed->getInfo('url'))->toBe("http://127.0.0.1:{$port}/echo?from=redirect");

        $stopped = symfonyClient(['max_redirects' => 0])->request('GET', "http://127.0.0.1:{$port}/redirect");

        expect($stopped->getStatusCode())->toBe(302)
            ->and($stopped->getInfo('redirect_url'))->toBe("http://127.0.0.1:{$port}/echo?from=redirect")
            ->and(fn () => $stopped->getContent())->toThrow(RedirectionException::class);
    } finally {
        $server->stop();
    }
});

it('times out on an idle server', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $response = (new FledgeSymfonyHttpClient)->request('GET', "http://127.0.0.1:{$port}/stall", ['timeout' => 0.2]);

        $start = microtime(true);

        expect(fn () => $response->getStatusCode())->toThrow(TimeoutException::class)
            ->and(microtime(true) - $start)->toBeLessThan(0.8)
            ->and($response->getInfo('error'))->not->toBeNull()
            ->and(fn () => $response->getContent())->toThrow(TransportException::class);
    } finally {
        $server->stop();
    }
});

it('rejects options it cannot honor', function (array $options, string $option) {
    expect(fn () => symfonyClient()->request('GET', 'http://127.0.0.1:1/', $options))
        ->toThrow(InvalidArgumentException::class, "Option \"{$option}\" is not supported");
})->with([
    'bindto' => [['bindto' => '127.0.0.1:0'], 'bindto'],
    'resolve' => [['resolve' => ['example.com' => '127.0.0.1']], 'resolve'],
    'peer_fingerprint' => [['peer_fingerprint' => ['pin-sha256' => ['AAAA']]], 'peer_fingerprint'],
]);

it('applies withOptions defaults without touching the original client', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $client = symfonyClient();
        $scoped = $client->withOptions(['base_uri' => "http://127.0.0.1:{$port}/", 'headers' => ['X-Test' => 'scoped']]);

        expect($scoped)->not->toBe($client)
            ->and($scoped->request('GET', 'echo')->toArray()['x_test'])->toBe('scoped')
            ->and(fn () => $client->request('GET', 'echo'))->toThrow(InvalidArgumentException::class);
    } finally {
        $server->stop();
    }
});

it('streams chunks for several responses', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $client = symfonyClient();

        $responses = [
            $client->request('GET', "http://127.0.0.1:{$port}/large"),
            $client->request('GET', "http://127.0.0.1:{$port}/echo"),
        ];

        $bodies = [];
        $firsts = 0;
        $lasts = 0;

        foreach ($client->stream($responses, LOOPBACK_TIMEOUT) as $response => $chunk) {
            $key = spl_object_id($response);
            $bodies[$key] ??= '';

            if ($chunk->isFirst()) {
                $firsts++;
            } elseif ($chunk->isLast()) {
                $lasts++;
            } else {
                $bodies[$key] .= $chunk->getContent();
            }
        }

        expect($firsts)->toBe(2)
            ->and($lasts)->toBe(2)
            ->and($bodies[spl_object_id($responses[0])])->toBe(str_repeat('abcdefgh', 64 * 1024))
            ->and(json_decode($bodies[spl_object_id($responses[1])], true)['method'])->toBe('GET')
            ->and($responses[0]->getContent())->toBe(str_repeat('abcdefgh', 64 * 1024));
    } finally {
        $server->stop();
    }
});

it('yields a timeout chunk while a streamed response idles', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $client = symfonyClient();
        $response = $client->request('GET', "http://127.0.0.1:{$port}/slow");

        $timeouts = 0;
        $content = '';

        foreach ($client->stream($response, 0.05) as $chunk) {
            if ($chunk->isTimeout()) {
                $timeouts++;

                continue;
            }

            $content .= $chunk->getContent();
        }

        expect($timeouts)->toBeGreaterThan(0)
            ->and($content)->toBe('slow');
    } finally {
        $server->stop();
    }
});

it('overlaps concurrent requests made from separate fibers', function () {
    [$server, $port] = startSymfonyLoopback();

    try {
        $client = symfonyClient();

        $start = microtime(true);

        $bodies = await([
            async(fn () => $client->request('GET', "http://127.0.0.1:{$port}/slow")->getContent()),
            async(fn () => $client->request('GET', "http://127.0.0.1:{$port}/slow")->getContent()),
        ]);

        $elapsed = microtime(true) - $start;

        expect($bodies)->toBe(['slow', 'slow'])
            ->and($elapsed)->toBeGreaterThanOrEqual(0.3)
            ->and($elapsed)->toBeLessThan(0.55);
    } finally {
        $server->stop();
    }
});
